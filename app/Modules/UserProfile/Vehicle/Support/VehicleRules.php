<?php

namespace App\Modules\UserProfile\Vehicle\Support;

use App\Enums\VehicleOwnerType;
use Illuminate\Validation\Rule;

/**
 * The single definition of what a valid vehicle payload looks like.
 *
 * Extracted in phase 15 so the Excel/CSV import validates a row with exactly
 * the rules StoreVehicleRequest applies to a manually created vehicle. Two
 * copies would eventually disagree, and the one that drifts would be the
 * import — the surface with no form in front of it to catch the difference.
 *
 * StoreVehicleRequest and UpdateVehicleRequest still own *which* rule set
 * applies (they resolve the B2B context and the caller's user type); this
 * class only owns the rules themselves.
 */
final class VehicleRules
{
    /**
     * Fields every create and update path shares.
     *
     * @return array<string, array<int, string>>
     */
    public static function commonFields(): array
    {
        return [
            'first_registration_date' => ['nullable', 'date'],
            'leasing_end_date' => ['nullable', 'date'],
            'leasinggeber' => ['nullable', 'string'],
            'vin' => ['nullable', 'string', 'size:17'],
            'make' => ['nullable', 'string'],
            'model' => ['nullable', 'string'],
        ];
    }

    /**
     * B2B fleet fields (§5), or a `prohibited` rule for each on a B2C payload
     * so a B2C caller can never set one even if the column exists.
     *
     * @return array<string, array<int, string>>
     */
    public static function b2bFields(bool $isB2bContext): array
    {
        if (! $isB2bContext) {
            return [
                'mileage' => ['prohibited'],
                'contract_number' => ['prohibited'],
                'cost_centre' => ['prohibited'],
                'driver_name' => ['prohibited'],
                'driver_contact' => ['prohibited'],
                'collection_address' => ['prohibited'],
            ];
        }

        return [
            'mileage' => ['nullable', 'integer', 'min:0', 'max:9999999'],
            'contract_number' => ['nullable', 'string', 'max:100'],
            'cost_centre' => ['nullable', 'string', 'max:100'],
            'driver_name' => ['nullable', 'string', 'max:255'],
            'driver_contact' => ['nullable', 'string', 'max:255'],
            'collection_address' => ['nullable', 'array'],
            'collection_address.street' => ['nullable', 'string', 'max:255'],
            'collection_address.number' => ['nullable', 'string', 'max:50'],
            'collection_address.additional_address' => ['nullable', 'string', 'max:255'],
            'collection_address.zip_code' => ['nullable', 'string', 'max:20'],
            'collection_address.city' => ['nullable', 'string', 'max:100'],
            'collection_address.country' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * The registration number. Unique across the whole table, not per company
     * — the constraint behind this rule (`vehicles_license_plate_unique`) is
     * global, so a collision may involve a vehicle the caller cannot see.
     *
     * @return array<int, string>
     */
    public static function licensePlate(): array
    {
        return ['required', 'string', 'unique:vehicles,license_plate'];
    }

    /**
     * Who the vehicle belongs to. Only ever validated for callers that are
     * allowed to say — an Admin. Firmenkunde and Privatkunde payloads have
     * their owner derived from the authenticated user in VehicleService, and
     * the import strips these keys before validation entirely.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function ownership(bool $isAdmin): array
    {
        return [
            'vehicle_belongs' => [
                Rule::requiredIf(fn () => $isAdmin),
                'nullable', 'string', Rule::in(VehicleOwnerType::values()),
            ],
            'b2b_id' => ['required_if:vehicle_belongs,B2B', 'nullable', 'uuid', 'exists:b2b,b2b_id'],
            'b2c_user_id' => ['required_if:vehicle_belongs,B2C', 'nullable', 'integer', 'exists:users,id'],
        ];
    }

    /**
     * Everything needed to create a vehicle, minus ownership.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function forCreation(bool $isB2bContext): array
    {
        return [
            ...self::b2bFields($isB2bContext),
            'license_plate' => self::licensePlate(),
            ...self::commonFields(),
        ];
    }

    /**
     * Everything an update may change. The registration number and the owner
     * are deliberately absent: neither is editable after creation.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function forUpdate(bool $isB2bContext): array
    {
        return [
            ...self::b2bFields($isB2bContext),
            ...self::commonFields(),
        ];
    }

    /**
     * German validation messages for the import.
     *
     * Deliberately **not** wired into StoreVehicleRequest: the app locale is
     * `en` and the existing forms rely on the default messages, so applying
     * these there would change what a B2C or Admin caller sees. The import is
     * a new surface with no such expectation, and its per-row errors are read
     * directly by the company user.
     *
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'required' => 'Bitte geben Sie :attribute an.',
            'string' => ':attribute muss ein Text sein.',
            'integer' => ':attribute muss eine Zahl sein.',
            'date' => ':attribute ist kein gültiges Datum.',
            'array' => ':attribute hat ein ungültiges Format.',
            'prohibited' => ':attribute darf hier nicht angegeben werden.',
            'min.numeric' => ':attribute darf nicht negativ sein.',
            'max.numeric' => ':attribute ist unplausibel hoch.',
            'max.string' => ':attribute ist zu lang (maximal :max Zeichen).',
            'vin.size' => 'Die FIN muss genau 17 Zeichen lang sein.',
            // Generic on purpose. The unique index is global, so the existing
            // vehicle may belong to another company or a B2C customer, and
            // naming it would disclose a record outside the caller's company.
            'license_plate.unique' => 'Dieses Kennzeichen ist bereits vergeben.',
        ];
    }

    /**
     * German field names for validation messages, so an import error reads
     * "Kennzeichen" rather than "license plate".
     *
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'license_plate' => 'Kennzeichen',
            'vin' => 'FIN',
            'make' => 'Hersteller',
            'model' => 'Modell',
            'first_registration_date' => 'Erstzulassung',
            'leasing_end_date' => 'Leasingende',
            'leasinggeber' => 'Leasinggeber',
            'mileage' => 'Kilometerstand',
            'contract_number' => 'Vertragsnummer',
            'cost_centre' => 'Kostenstelle',
            'driver_name' => 'Fahrer',
            'driver_contact' => 'Kontakt',
            'collection_address' => 'Abholadresse',
            'collection_address.street' => 'Straße',
            'collection_address.number' => 'Hausnummer',
            'collection_address.additional_address' => 'Adresszusatz',
            'collection_address.zip_code' => 'PLZ',
            'collection_address.city' => 'Ort',
            'collection_address.country' => 'Land',
        ];
    }
}
