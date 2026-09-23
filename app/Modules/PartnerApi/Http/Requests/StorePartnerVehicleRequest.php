<?php

namespace App\Modules\PartnerApi\Http\Requests;

use App\Modules\UserProfile\Vehicle\Support\VehicleRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Creating a vehicle over the Partner API.
 *
 * The rules themselves are VehicleRules', unchanged — the same definition
 * StoreVehicleRequest applies to a human in the portal and VehicleImportService
 * applies to a spreadsheet row. A partner-specific copy would eventually
 * disagree with the portal about what a valid registration number is, and the
 * surface with no form in front of it would be the one that drifted.
 *
 * Two things are added rather than changed:
 *
 * 1. `true` is passed to VehicleRules::forCreation() unconditionally. Every
 *    vehicle this API can create belongs to a company, so the B2B fleet fields
 *    are always available and never `prohibited`.
 * 2. VehicleRules::ownership() is never applied. Ownership is the token's, and
 *    `vehicle_belongs` is prohibited outright — `b2b_id` and its siblings never
 *    reach validation at all, because `partner.no-ownership` refuses the
 *    request before this class is constructed.
 */
class StorePartnerVehicleRequest extends FormRequest
{
    /** Authorisation is the token's scope and the company permission, both middleware. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...VehicleRules::forCreation(true),
            'external_vehicle_id' => ['nullable', 'string', 'max:191'],
            // Prohibited, not ignored. A partner that sends this has
            // misunderstood the API, and silently dropping it would let them
            // ship code believing they chose the owner.
            'vehicle_belongs' => ['prohibited'],
        ];
    }

    public function externalVehicleId(): ?string
    {
        $value = trim((string) $this->input('external_vehicle_id', ''));

        return $value === '' ? null : $value;
    }

    /**
     * The payload VehicleService::createVehicle() expects — our own key
     * removed, so nothing partner-specific reaches the shared service.
     *
     * @return array<string, mixed>
     */
    public function vehicleAttributes(): array
    {
        return collect($this->validated())
            ->except(['external_vehicle_id', 'vehicle_belongs'])
            ->all();
    }
}
