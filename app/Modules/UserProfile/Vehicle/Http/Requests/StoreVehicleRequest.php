<?php

namespace App\Modules\UserProfile\Vehicle\Http\Requests;

use App\Modules\UserProfile\Vehicle\Support\VehicleRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreVehicleRequest extends FormRequest
{
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
            ...VehicleRules::forCreation($this->isB2bContext()),
            ...VehicleRules::ownership($this->user()?->user_type?->value === 'Admin'),
        ];
    }
}
