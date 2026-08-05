<?php

namespace App\Modules\UserProfile\Order\Services;

use App\Models\User;
use App\Models\Vehicle;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\LogisticsAddressProfile;
use App\Modules\UserProfile\Order\Models\OrderLogistics;
use Illuminate\Support\Collection;

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

    public function updateByAdmin(LeasybackOrder $order, Vehicle $vehicle, User $user, array $validated): void
    {
        if ($vehicle->vehicle_belongs !== 'B2B') {
            return;
        }

        $logistics = OrderLogistics::where('auftragsnummer', $order->auftragsnummer)->first();
        $address = $this->normalizeAddress($validated['collection_address'] ?? null);
        $profileId = $logistics?->pickup_profile_id ?? $vehicle->collection_address_profile_id;
        $profileDetails = $profileId === null
            ? null
            : LogisticsAddressProfile::where('id', $profileId)->value('details');

        OrderLogistics::updateOrCreate(
            ['auftragsnummer' => $order->auftragsnummer],
            [
                'confirmed_collection_date' => $this->trimToNull($validated['confirmed_collection_date'] ?? null),
                'internal_note' => $this->trimToNull($validated['internal_note'] ?? null),
                'updated_by_user_id' => $user->id,
                ...($address === null && $logistics !== null
                    ? []
                    : $this->addressColumns($address, $profileId, $profileDetails)),
            ],
        );
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
        if ($address === null || ($profileDetails !== null && $address === $profileDetails)) {
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
