<?php

namespace App\Modules\UserProfile\Vehicle\Http\Requests;

use App\Enums\VehicleOwnerType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
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
