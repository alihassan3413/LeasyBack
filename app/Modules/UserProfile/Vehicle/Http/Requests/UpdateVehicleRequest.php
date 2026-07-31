<?php

namespace App\Modules\UserProfile\Vehicle\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
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
}
