<?php

namespace App\Modules\UserProfile\Vehicle\Http\Requests;

use App\Modules\UserProfile\Vehicle\Support\VehicleRules;

class UpdateWebVehicleRequest extends UpdateVehicleRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            ...VehicleRules::mandatoryWebFields(onlyWhenPresent: true),
        ];
    }

    public function messages(): array
    {
        return VehicleRules::mandatoryWebMessages();
    }
}
