<?php

namespace App\Modules\UserProfile\Vehicle\Http\Requests;

use App\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Support\VehicleRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    private function isB2bContext(): bool
    {
        $vehicleId = $this->route('vehicleId');

        if (! is_string($vehicleId) || $vehicleId === '') {
            return false;
        }

        return Vehicle::where('vehicle_id', $vehicleId)->value('vehicle_belongs') === 'B2B';
    }

    public function rules(): array
    {
        return VehicleRules::forUpdate($this->isB2bContext());
    }
}
