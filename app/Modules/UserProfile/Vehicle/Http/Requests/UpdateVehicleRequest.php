<?php

namespace App\Modules\UserProfile\Vehicle\Http\Requests;

use App\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Http\Requests\Concerns\ValidatesB2bVehicleFields;
use Illuminate\Foundation\Http\FormRequest;

class UpdateVehicleRequest extends FormRequest
{
    use ValidatesB2bVehicleFields;

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
        return [
            ...$this->b2bFieldRules($this->isB2bContext()),
            'first_registration_date' => ['nullable', 'date'],
            'leasing_end_date' => ['nullable', 'date'],
            'leasinggeber' => ['nullable', 'string'],
            'vin' => ['nullable', 'string', 'size:17'],
            'make' => ['nullable', 'string'],
            'model' => ['nullable', 'string'],
        ];
    }
}
