<?php

namespace App\Modules\UserProfile\Vehicle\Http\Requests;

use App\Enums\VehicleOwnerType;
use App\Modules\UserProfile\Vehicle\Http\Requests\Concerns\ValidatesB2bVehicleFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVehicleRequest extends FormRequest
{
    use ValidatesB2bVehicleFields;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    private function isB2bContext(): bool
    {
        $userType = $this->user()?->user_type?->value;

        if ($userType === 'Firmenkunde') {
            return true;
        }

        return $userType === 'Admin' && $this->input('vehicle_belongs') === 'B2B';
    }

    public function rules(): array
    {
        return [
            ...$this->b2bFieldRules($this->isB2bContext()),
            'license_plate' => ['required', 'string', 'unique:vehicles,license_plate'],
            'first_registration_date' => ['nullable', 'date'],
            'leasing_end_date' => ['nullable', 'date'],
            'leasinggeber' => ['nullable', 'string'],
            'vin' => ['nullable', 'string', 'size:17'],
            'make' => ['nullable', 'string'],
            'model' => ['nullable', 'string'],
            'vehicle_belongs' => [
                Rule::requiredIf(fn () => $this->user()?->user_type?->value === 'Admin'),
                'nullable', 'string', Rule::in(VehicleOwnerType::values()),
            ],
            'b2b_id' => ['required_if:vehicle_belongs,B2B', 'nullable', 'uuid', 'exists:b2b,b2b_id'],
            'b2c_user_id' => ['required_if:vehicle_belongs,B2C', 'nullable', 'integer', 'exists:users,id'],
        ];
    }
}
