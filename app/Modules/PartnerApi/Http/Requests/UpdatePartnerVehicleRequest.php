<?php

namespace App\Modules\PartnerApi\Http\Requests;

use App\Modules\UserProfile\Vehicle\Support\VehicleRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Updating a vehicle over the Partner API.
 *
 * VehicleRules::forUpdate() already omits the registration number and the
 * owner, because neither is editable after creation anywhere in the
 * application. Both are restated as `prohibited` here rather than left out:
 * omitted, an attempt to change a plate is silently dropped and the partner
 * believes it worked; prohibited, they get a 422 naming the field.
 */
class UpdatePartnerVehicleRequest extends FormRequest
{
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
            ...VehicleRules::forUpdate(true),
            'external_vehicle_id' => ['nullable', 'string', 'max:191'],
            'license_plate' => ['prohibited'],
            'vehicle_belongs' => ['prohibited'],
        ];
    }

    public function externalVehicleId(): ?string
    {
        $value = trim((string) $this->input('external_vehicle_id', ''));

        return $value === '' ? null : $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function vehicleAttributes(): array
    {
        return collect($this->validated())
            ->except(['external_vehicle_id', 'license_plate', 'vehicle_belongs'])
            ->all();
    }
}
