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
            'vehicle_belongs' => ['nullable', 'string', Rule::in(VehicleOwnerType::values())],
            'b2b_id' => ['nullable', 'uuid'],
            'b2c_user_id' => ['nullable', 'integer'],
        ];
    }
}
