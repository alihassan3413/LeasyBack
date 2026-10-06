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
use App\Support\PortalTimestamp;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * B2B-only collection appointment handling on top of the existing
 * leasyback_order_logistics row: the customer's requested date, the address
 * (defaulted from the vehicle's logistics_address_profiles link rather than
 * copied) and the note, plus Admin's confirmed date and internal note.
 *
 * An Überführung uses the same row for its appointment — confirmed date plus
 * a full time slot — and for its transfer protocol.
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

    /**
     * What operations arranged for an Unfallschaden (Accident Damage brief:
     * "The inspection, vehicle access or collection has been arranged").
     */
    public const ACCIDENT_ARRANGEMENTS = [
        'inspection' => 'Begutachtung vor Ort',
        'vehicle_access' => 'Fahrzeugzugang',
        'collection' => 'Abholung',
    ];

    /** An Überführung time window must span at least this long — the customer form's rule. */
    public const RELOCATION_MIN_WINDOW_MINUTES = 120;

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
            'requested_collection_time_slot' => [
                'required',
                'string',
                'in:08:00-10:00,10:00-12:00,12:00-14:00,14:00-16:00,16:00-18:00',
            ],
            'collection_address' => ['required', 'array'],
            'collection_address.street' => ['required', 'string', 'max:255'],
            'collection_address.zip_code' => ['required', 'string', 'max:20'],
            'collection_address.city' => ['required', 'string', 'max:100'],
        ];
    }

    /**
     * `confirmed_time_from`/`confirmed_time_to` are only read for an
     * Überführung, which is scheduled by a date *and* a time window.
     *
     * @return array<string, array<int, string>>
     */
    public static function adminRules(): array
    {
        return [
            'confirmed_collection_date' => ['nullable', 'date_format:Y-m-d'],
            'confirmed_time_from' => ['nullable', 'date_format:H:i'],
            'confirmed_time_to' => ['nullable', 'date_format:H:i'],
            // Unfallschaden only: what was arranged.
            'confirmed_arrangement' => ['nullable', 'string', 'in:'.implode(',', array_keys(self::ACCIDENT_ARRANGEMENTS))],
            // Gutachten only: the inspection site and whether the transport there is arranged.
            'inspection_site_name' => ['nullable', 'string', 'max:255'],
            'inspection_site_address' => ['nullable', 'string', 'max:500'],
            'transport_confirmed' => ['nullable', 'boolean'],
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
        $requestedTimeSlot = $this->trimToNull(
            $validated['requested_collection_time_slot'] ?? null
        );
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
                'requested_collection_time_slot' => $requestedTimeSlot,
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
     * An Überführung is scheduled only by a date *together with* a full time
     * window (von/bis, at least two hours) — the traffic-light spec's
     * condition for completing its first Admin task.
     *
     * @param  array<string, mixed>  $validated
     */
    public function updateByAdmin(LeasybackOrder $order, Vehicle $vehicle, User $user, array $validated): void
    {
        if ($vehicle->vehicle_belongs !== 'B2B') {
            return;
        }

        $order = $order->fresh() ?? $order;
        $isRelocation = TransitionOrderStatus::isRelocationOrder($order);
        $isAccident = TransitionOrderStatus::isAccidentDamageOrder($order);
        $isAppraisal = TransitionOrderStatus::isAppraisalOrder($order);
        // Überführung and Gutachten are both scheduled by a date together with a full time window.
        $needsTimeWindow = $isRelocation || $isAppraisal;

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

        // The Überführung's time window. Resolved before anything is written,
        // so a refused save changes nothing.
        $slotSent = $needsTimeWindow
            && (array_key_exists('confirmed_time_from', $validated) || array_key_exists('confirmed_time_to', $validated));
        $confirmedSlot = $needsTimeWindow
            ? ($slotSent
                ? $this->relocationTimeSlot($validated['confirmed_time_from'] ?? null, $validated['confirmed_time_to'] ?? null)
                : $this->trimToNull($logistics?->confirmed_collection_time_slot ?? null))
            : null;

        if ($needsTimeWindow && $confirmedDate !== null && $confirmedSlot === null) {
            throw ValidationException::withMessages([
                'confirmed_time_from' => 'Bitte geben Sie ein vollständiges Zeitfenster (von/bis) an.',
            ]);
        }

        // Unfallschaden: scheduled by the kind of step together with its date.
        $arrangementSent = $isAccident && array_key_exists('confirmed_arrangement', $validated);
        $confirmedArrangement = $isAccident
            ? ($arrangementSent
                ? $this->trimToNull($validated['confirmed_arrangement'])
                : $this->trimToNull($logistics?->confirmed_arrangement ?? null))
            : null;

        if ($isAccident && $confirmedDate !== null && $confirmedArrangement === null) {
            throw ValidationException::withMessages([
                'confirmed_arrangement' => 'Bitte wählen Sie, was vereinbart wurde (Begutachtung, Fahrzeugzugang oder Abholung).',
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

        // Written directly so the column does not depend on the model's
        // fillable list. Before scheduling, because TransitionOrderStatus
        // checks that date and slot exist before it schedules a relocation.
        if ($slotSent) {
            DB::table('leasyback_order_logistics')
                ->where('auftragsnummer', $order->auftragsnummer)
                ->update(['confirmed_collection_time_slot' => $confirmedSlot]);
        }

        if ($arrangementSent) {
            DB::table('leasyback_order_logistics')
                ->where('auftragsnummer', $order->auftragsnummer)
                ->update(['confirmed_arrangement' => $confirmedArrangement]);
        }

        // Gutachten: where the inspection takes place and whether the transport
        // there is arranged. Only the fields actually sent are written.
        if ($isAppraisal) {
            $site = [];

            foreach (['inspection_site_name', 'inspection_site_address'] as $field) {
                if (array_key_exists($field, $validated)) {
                    $site[$field] = $this->trimToNull($validated[$field]);
                }
            }

            if (array_key_exists('transport_confirmed', $validated)) {
                $site['transport_confirmed'] = (bool) $validated['transport_confirmed'];
            }

            if ($site !== []) {
                DB::table('leasyback_order_logistics')
                    ->where('auftragsnummer', $order->auftragsnummer)
                    ->update($site);
            }
        }

        $this->announceCollectionChange($order, $vehicle, $previousDate, $confirmedDate);

        if ($confirmedDate === null) {
            return;
        }

        $this->scheduleCollection($order, $user);

        // The first confirmation is announced by the `confirmed` status mail.
        // A date that moves afterwards has no status change to carry it.
        if (! $isAppraisal && $previousDate !== null && $confirmedDate !== $previousDate) {
            $this->orderMailer->collectionRescheduled($order->fresh() ?? $order, $vehicle);
        }
    }

    /**
     * "08:00-12:00" from a von/bis pair, or null when both are empty. One half
     * without the other, or a window under two hours, is refused.
     */
    private function relocationTimeSlot(mixed $from, mixed $to): ?string
    {
        $from = $this->trimToNull($from);
        $to = $this->trimToNull($to);

        if ($from === null && $to === null) {
            return null;
        }

        if ($from === null || $to === null) {
            throw ValidationException::withMessages([
                'confirmed_time_from' => 'Bitte geben Sie ein vollständiges Zeitfenster (von/bis) an.',
            ]);
        }

        $minutes = fn (string $time): int => ((int) substr($time, 0, 2)) * 60 + (int) substr($time, 3, 2);

        if ($minutes($to) - $minutes($from) < self::RELOCATION_MIN_WINDOW_MINUTES) {
            throw ValidationException::withMessages([
                'confirmed_time_to' => 'Das Zeitfenster muss mindestens 2 Stunden umfassen.',
            ]);
        }

        return $from.'-'.$to;
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
     * The customer-facing shape. `internal_note` and the transfer protocol
     * are deliberately absent — only the Admin read ($includeInternal) returns
     * them.
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
                'requested_collection_time_slot' => $row->requested_collection_time_slot,
                // The Überführung's confirmed window; null for every other order.
                'confirmed_collection_time_slot' => $row->confirmed_collection_time_slot,
                // The Unfallschaden's arranged step (inspection / vehicle_access / collection).
                'confirmed_arrangement' => $row->confirmed_arrangement ?? null,
                // The Gutachten's inspection site and transport confirmation — logistics the customer sees too.
                'inspection_site_name' => $row->inspection_site_name ?? null,
                'inspection_site_address' => $row->inspection_site_address ?? null,
                'transport_confirmed' => (bool) ($row->transport_confirmed ?? false),
                // Customer-visible business dates (§11/§15), unlike
                // `internal_note` below which stays gated on $includeInternal.
                'confirmed_repair_start_date' => $row->confirmed_repair_start_date?->toDateString(),
                'estimated_processing_days' => $row->estimated_processing_days,
                'collection_address' => $row->pickup_details
                    ?? ($row->pickup_profile_id === null ? null : ($profiles[$row->pickup_profile_id] ?? null)),
                'collection_note' => $row->pickup_notes,
                ...($includeInternal ? [
                    'internal_note' => $row->internal_note,
                    'transfer_protocol' => self::presentTransferProtocol($row),
                ] : []),
            ],
        ])->all();
    }

    /**
     * The saved Übergabeprotokoll, or null. A PDF is handed out as a
     * short-lived signed link; a disk that cannot sign links leaves it null.
     *
     * @return array{format: string, url: string|null, file_url: string|null, file_name: string|null, saved_at: string|null}|null
     */
    public static function presentTransferProtocol(object $row): ?array
    {
        if (($row->transfer_protocol_saved_at ?? null) === null) {
            return null;
        }

        $path = $row->transfer_protocol_path ?? null;
        $fileUrl = null;

        if ($path !== null) {
            try {
                $fileUrl = Storage::disk(RelocationTransferProtocolService::DISK)->temporaryUrl($path, now()->addMinutes(30));
            } catch (\Throwable) {
                $fileUrl = null;
            }
        }

        return [
            'format' => $path !== null ? 'pdf' : 'link',
            'url' => $row->transfer_protocol_url ?? null,
            'file_url' => $fileUrl,
            'file_name' => $row->transfer_protocol_original_name ?? null,
            'saved_at' => PortalTimestamp::iso($row->transfer_protocol_saved_at),
        ];
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