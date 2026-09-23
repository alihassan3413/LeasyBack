<?php

namespace App\Modules\UserProfile\Order\Services;

use App\Models\OrderAuditLog;
use App\Models\User;
use App\Models\Vehicle;
use App\Modules\PartnerApi\Services\PartnerWebhookEvents;
use App\Modules\UserProfile\Order\Actions\TransitionOrderStatus;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\LogisticsAddressProfile;
use App\Modules\UserProfile\Order\Models\OrderLogistics;
use App\Services\Mail\OrderMailer;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * B2B-only collection appointment handling on top of the existing
 * leasyback_order_logistics row: the customer's requested date, the address
 * (defaulted from the vehicle's logistics_address_profiles link rather than
 * copied) and the note, plus Admin's confirmed date and internal note.
 *
 * B2C orders never get a row here — every write path is guarded on the
 * vehicle being B2B, and the customer-facing read path never returns
 * internal_note.
 */
class OrderCollectionService
{
    public const ADDRESS_FIELDS = ['street', 'number', 'additional_address', 'zip_code', 'city', 'country'];

    /**
     * The statuses in which the collection appointment is still being planned.
     * Once the vehicle has been collected the appointment is history; on a
     * closed order there is nothing left to plan.
     */
    public const COLLECTION_EDITABLE_STATUSES = ['order_requested', 'order_placed', 'confirmed'];

    /**
     * The statuses in which a repair appointment can be entered: the workshop
     * has been instructed and the repair has not finished. `reworkshop` is the
     * B2C second repair round.
     */
    public const REPAIR_APPOINTMENT_STATUSES = ['workshop_commissioned', 'workshop', 'reworkshop'];

    public function __construct(
        private readonly TransitionOrderStatus $transitionOrderStatus,
        private readonly PartnerWebhookEvents $webhooks,
        private readonly OrderMailer $orderMailer,
    ) {}

    /**
     * @return array<string, array<int, string>>
     */
    public static function customerRules(bool $isB2b): array
    {
        if (! $isB2b) {
            return [
                'requested_collection_date' => ['prohibited'],
                'collection_address' => ['prohibited'],
                'collection_note' => ['prohibited'],
            ];
        }

        return [
            'requested_collection_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'collection_note' => ['nullable', 'string', 'max:2000'],
            ...self::addressRules(),
        ];
    }

    /**
     * A B2B collection order carries no inspection station and no TÜV SÜD
     * appointment — those keys are rejected outright rather than ignored, so
     * a client that still sends them learns it is on the wrong flow.
     *
     * @return array<string, array<int, string>>
     */
    public static function b2bOrderRules(): array
    {
        return [
            'station_id' => ['prohibited'],
            'termin' => ['prohibited'],
            'provider' => ['prohibited'],
            'remarks' => ['prohibited'],
            ...self::customerRules(true),
            'requested_collection_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'collection_address' => ['required', 'array'],
            'collection_address.street' => ['required', 'string', 'max:255'],
            'collection_address.zip_code' => ['required', 'string', 'max:20'],
            'collection_address.city' => ['required', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function adminRules(): array
    {
        return [
            'confirmed_collection_date' => ['nullable', 'date_format:Y-m-d'],
            'internal_note' => ['nullable', 'string', 'max:2000'],
            ...self::addressRules(),
        ];
    }

    /**
     * The date is a plain `Y-m-d` calendar day and is stored as a date cast, so
     * no timezone conversion is involved — a repair starting on the 4th starts
     * on the 4th wherever it is read. Deliberately no lead-time rule: a workshop
     * that can take the car tomorrow, or one already holding it, is a normal
     * case and not a validation error. Backdating is allowed for the same
     * reason — appointments get recorded after the fact.
     *
     * @return array<string, array<int, string>>
     */
    public static function repairAppointmentRules(): array
    {
        return [
            'confirmed_repair_start_date' => ['required', 'date_format:Y-m-d'],
            'estimated_processing_days' => ['nullable', 'integer', 'min:0', 'max:365'],
        ];
    }

    /**
     * Records the confirmed workshop appointment and, when the order is still
     * waiting on it, moves it into repair.
     *
     * Both channels. The appointment is a fact about a car and a workshop, not
     * about who owns the car; only the collection half of this service, which
     * moves a fleet vehicle to and from LeasyBack, is genuinely B2B.
     *
     * The transition lives here rather than in the controller so the §11 rule
     * "when the appointment is saved the status changes to In repair" cannot
     * be bypassed by a different caller. It only fires from
     * `workshop_commissioned`, so rescheduling an order that is already in
     * repair updates the dates and leaves the status alone —
     * TransitionOrderStatus would reject the edge anyway. That also makes a
     * resubmitted appointment safe: the second save rewrites the same dates and
     * transitions nothing.
     *
     * @param  array<string, mixed>  $validated
     */
    public function updateRepairAppointment(LeasybackOrder $order, Vehicle $vehicle, User $user, array $validated): void
    {
        $order = $order->fresh() ?? $order;

        // Only once the workshop is commissioned and until the repair is done.
        // Saved any earlier, the appointment marked its own task as done while
        // the transition into repair never happened, and the order sat in
        // `workshop_commissioned` with no open task at all.
        if (! in_array($order->order_status, self::REPAIR_APPOINTMENT_STATUSES, true)) {
            throw ValidationException::withMessages([
                'confirmed_repair_start_date' => 'Ein Reparaturtermin kann erst nach der Beauftragung der Werkstatt und nur bis zum Abschluss der Reparatur erfasst werden.',
            ]);
        }

        $existing = OrderLogistics::where('auftragsnummer', $order->auftragsnummer)->first();
        $date = $this->trimToNull($validated['confirmed_repair_start_date'] ?? null);
        $days = $validated['estimated_processing_days'] ?? null;

        OrderLogistics::updateOrCreate(
            ['auftragsnummer' => $order->auftragsnummer],
            [
                'confirmed_repair_start_date' => $date,
                'estimated_processing_days' => $days,
                'updated_by_user_id' => $user->id,
            ],
        );

        $this->auditAppointment($order, $user, $existing, $date, $days);

        if ($order->order_status === 'workshop_commissioned') {
            $this->transitionOrderStatus->__invoke(
                $order,
                'workshop',
                'admin',
                $user->name ?? $user->email,
                $user->id,
                request()?->ip(),
            );
        }
    }

    /**
     * The appointment is business data an admin agreed with a workshop, so a
     * change to it is worth the same trail as any other lifecycle touchpoint.
     * The status change it may cause is recorded separately by
     * TransitionOrderStatus; this records the dates themselves. A save that
     * changes nothing writes nothing.
     */
    private function auditAppointment(
        LeasybackOrder $order,
        User $user,
        ?OrderLogistics $existing,
        ?string $date,
        mixed $days,
    ): void {
        $old = [
            'confirmed_repair_start_date' => $existing?->confirmed_repair_start_date?->toDateString(),
            'estimated_processing_days' => $existing?->estimated_processing_days,
        ];
        $new = [
            'confirmed_repair_start_date' => $date,
            'estimated_processing_days' => $days === null ? null : (int) $days,
        ];

        if ($old == $new) {
            return;
        }

        OrderAuditLog::create([
            'order_id' => $order->id,
            'vehicle_id' => $order->vehicle_id,
            'action' => 'REPAIR_APPOINTMENT_SET',
            'old_values' => $existing === null ? null : $old,
            'new_values' => $new,
            'changed_by_user_id' => $user->id,
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private static function addressRules(): array
    {
        return [
            'collection_address' => ['nullable', 'array'],
            'collection_address.street' => ['nullable', 'string', 'max:255'],
            'collection_address.number' => ['nullable', 'string', 'max:50'],
            'collection_address.additional_address' => ['nullable', 'string', 'max:255'],
            'collection_address.zip_code' => ['nullable', 'string', 'max:20'],
            'collection_address.city' => ['nullable', 'string', 'max:100'],
            'collection_address.country' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function recordCustomerRequest(LeasybackOrder $order, Vehicle $vehicle, User $user, array $validated): void
    {
        if ($vehicle->vehicle_belongs !== 'B2B') {
            return;
        }

        $address = $this->normalizeAddress($validated['collection_address'] ?? null);
        $note = $this->trimToNull($validated['collection_note'] ?? null);
        $requestedDate = $this->trimToNull($validated['requested_collection_date'] ?? null);

        if ($address === null && $note === null && $requestedDate === null && $vehicle->collection_address_profile_id === null) {
            return;
        }

        $profileId = $vehicle->collection_address_profile_id;
        $profileDetails = $profileId === null
            ? null
            : LogisticsAddressProfile::where('id', $profileId)->value('details');

        OrderLogistics::updateOrCreate(
            ['auftragsnummer' => $order->auftragsnummer],
            [
                'requested_collection_date' => $requestedDate,
                'pickup_notes' => $note,
                'created_by_user_id' => $user->id,
                'updated_by_user_id' => $user->id,
                ...$this->addressColumns($address, $profileId, $profileDetails),
            ],
        );
    }

    /**
     * Admin's side of the appointment (§7): confirm the requested date or pick
     * another, plus the address and an internal note.
     *
     * Only the fields actually sent are written. A request that carries just
     * the address used to null the confirmed date and the internal note — and,
     * with the date gone, re-open the confirmation task while partners still
     * believed in the old appointment.
     *
     * Confirming a date is what moves the order to "Collection scheduled"
     * (`confirmed`). A still-unreleased order is released on the way, because
     * confirming LeasyBack's own collection *is* the review §6 asks for; both
     * transitions are recorded individually by TransitionOrderStatus.
     *
     * @param  array<string, mixed>  $validated
     */
    public function updateByAdmin(LeasybackOrder $order, Vehicle $vehicle, User $user, array $validated): void
    {
        if ($vehicle->vehicle_belongs !== 'B2B') {
            return;
        }

        $order = $order->fresh() ?? $order;

        if (! in_array($order->order_status, self::COLLECTION_EDITABLE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'confirmed_collection_date' => 'Der Abholtermin kann nach der Abholung oder bei einem abgeschlossenen Auftrag nicht mehr geändert werden.',
            ]);
        }

        $logistics = OrderLogistics::where('auftragsnummer', $order->auftragsnummer)->first();
        $previousDate = $this->asDateString($logistics?->confirmed_collection_date);
        $confirmedDate = array_key_exists('confirmed_collection_date', $validated)
            ? $this->trimToNull($validated['confirmed_collection_date'])
            : $previousDate;

        // A new date cannot lie in the past; an unchanged one may, so saving
        // the note on an order whose appointment is today or earlier still works.
        if ($confirmedDate !== null && $confirmedDate !== $previousDate && $confirmedDate < now()->toDateString()) {
            throw ValidationException::withMessages([
                'confirmed_collection_date' => 'Der Abholtermin darf nicht in der Vergangenheit liegen.',
            ]);
        }

        $attributes = ['updated_by_user_id' => $user->id];

        if (array_key_exists('confirmed_collection_date', $validated)) {
            $attributes['confirmed_collection_date'] = $confirmedDate;
        }

        if (array_key_exists('internal_note', $validated)) {
            $attributes['internal_note'] = $this->trimToNull($validated['internal_note']);
        }

        if (array_key_exists('collection_address', $validated)) {
            $address = $this->normalizeAddress($validated['collection_address']);
            $profileId = $logistics?->pickup_profile_id ?? $vehicle->collection_address_profile_id;
            $profileDetails = $profileId === null
                ? null
                : LogisticsAddressProfile::where('id', $profileId)->value('details');

            if ($address !== null || $logistics === null) {
                $attributes = [...$attributes, ...$this->addressColumns($address, $profileId, $profileDetails)];
            }
        } elseif ($logistics === null) {
            $attributes = [...$attributes, ...$this->addressColumns(null, $vehicle->collection_address_profile_id, null)];
        }

        OrderLogistics::updateOrCreate(['auftragsnummer' => $order->auftragsnummer], $attributes);

        $this->announceCollectionChange($order, $vehicle, $previousDate, $confirmedDate);

        if ($confirmedDate === null) {
            return;
        }

        $this->scheduleCollection($order, $user);

        // The first confirmation is announced by the `confirmed` status mail.
        // A date that moves afterwards has no status change to carry it.
        if ($previousDate !== null && $confirmedDate !== $previousDate) {
            $this->orderMailer->collectionRescheduled($order->fresh() ?? $order, $vehicle);
        }
    }

    /**
     * Walks a not-yet-scheduled order to `confirmed` once its collection date
     * is set. Each step goes through TransitionOrderStatus, so each is recorded
     * and announced exactly as if it had been clicked separately.
     */
    private function scheduleCollection(LeasybackOrder $order, User $user): void
    {
        $label = $user->name ?? $user->email;
        $ip = request()?->ip();

        if ($order->order_status === 'order_requested') {
            $order = $this->transitionOrderStatus->__invoke($order, 'order_placed', 'admin', $label, $user->id, $ip);

            OrderAuditLog::create([
                'order_id' => $order->id,
                'vehicle_id' => $order->vehicle_id,
                'action' => 'APPROVE_ORDER',
                'old_values' => ['order_status' => 'order_requested'],
                'new_values' => ['order_status' => 'order_placed'],
                'changed_by_user_id' => $user->id,
            ]);
        }

        if ($order->order_status === 'order_placed') {
            $this->transitionOrderStatus->__invoke($order, 'confirmed', 'admin', $label, $user->id, $ip);
        }
    }

    /**
     * Confirmed for the first time, or moved.
     *
     * Two events rather than one because they mean different things to a
     * partner: a confirmation is the appointment being set, a reschedule is one
     * they may already have told a driver about. `internal_note` is not carried
     * by either — it is the §16 internal side of this row and never leaves.
     *
     * Nothing is emitted when the date did not change, so an Admin saving the
     * address or the note does not look like an appointment change.
     */
    private function announceCollectionChange(
        LeasybackOrder $order,
        Vehicle $vehicle,
        ?string $previousDate,
        ?string $confirmedDate,
    ): void {
        if ($confirmedDate === null || $confirmedDate === $previousDate) {
            return;
        }

        if ($previousDate === null) {
            $this->webhooks->collectionConfirmed($order, $confirmedDate, $vehicle);

            return;
        }

        $this->webhooks->collectionRescheduled($order, $confirmedDate, $previousDate, $vehicle);
    }

    private function asDateString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : (string) $value;
    }

    /**
     * The customer-facing shape. `internal_note` is deliberately absent —
     * only forAdmin() returns it.
     *
     * @param  array<int, string>  $auftragsnummern
     * @return array<string, array<string, mixed>>
     */
    public function forOrders(array $auftragsnummern, bool $includeInternal = false): array
    {
        if ($auftragsnummern === []) {
            return [];
        }

        $rows = OrderLogistics::whereIn('auftragsnummer', $auftragsnummern)->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $profiles = $this->profileDetails($rows);

        return $rows->mapWithKeys(fn (OrderLogistics $row) => [
            $row->auftragsnummer => [
                'requested_collection_date' => $row->requested_collection_date?->toDateString(),
                'confirmed_collection_date' => $row->confirmed_collection_date?->toDateString(),
                // Customer-visible business dates (§11/§15), unlike
                // `internal_note` below which stays gated on $includeInternal.
                'confirmed_repair_start_date' => $row->confirmed_repair_start_date?->toDateString(),
                'estimated_processing_days' => $row->estimated_processing_days,
                'collection_address' => $row->pickup_details
                    ?? ($row->pickup_profile_id === null ? null : ($profiles[$row->pickup_profile_id] ?? null)),
                'collection_note' => $row->pickup_notes,
                ...($includeInternal ? ['internal_note' => $row->internal_note] : []),
            ],
        ])->all();
    }

    /**
     * @param  Collection<int, OrderLogistics>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function profileDetails(Collection $rows): array
    {
        $ids = $rows->pluck('pickup_profile_id')->filter()->unique()->all();

        if ($ids === []) {
            return [];
        }

        return LogisticsAddressProfile::whereIn('id', $ids)
            ->get()
            ->mapWithKeys(fn (LogisticsAddressProfile $profile) => [$profile->id => $profile->details])
            ->all();
    }

    /**
     * Keeps the vehicle's profile as the single source of truth whenever the
     * order uses that same address: pickup_details is only written when the
     * order genuinely deviates and needs its own snapshot.
     *
     * @return array<string, mixed>
     */
    private function addressColumns(?array $address, ?string $profileId, ?array $profileDetails): array
    {
        // Compared in normalised form: the profile's stored details may order
        // their keys differently or omit empty ones, and a strict comparison of
        // the raw arrays detached an unchanged address from its profile.
        if ($address === null || ($profileDetails !== null && $address === $this->normalizeAddress($profileDetails))) {
            return ['pickup_profile_id' => $profileId, 'pickup_details' => null];
        }

        return ['pickup_profile_id' => null, 'pickup_details' => $address];
    }

    /**
     * @return array<string, string|null>|null
     */
    private function normalizeAddress(?array $address): ?array
    {
        if ($address === null) {
            return null;
        }

        $details = [];

        foreach (self::ADDRESS_FIELDS as $field) {
            $details[$field] = $this->trimToNull($address[$field] ?? null);
        }

        return collect($details)->filter()->isEmpty() ? null : $details;
    }

    private function trimToNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return $value === null ? null : (string) $value;
        }

        return trim($value) === '' ? null : trim($value);
    }
}
