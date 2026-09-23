<?php

namespace App\Modules\UserProfile\Vehicle\Http\Requests;

use App\Modules\UserProfile\Vehicle\Support\VehicleRules;

class StoreWebVehicleRequest extends StoreVehicleRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            ...VehicleRules::mandatoryWebFields(),
        ];
    }

    public function messages(): array
    {
        return VehicleRules::mandatoryWebMessages();
    }
}
