<?php

namespace App\Modules\UserProfile\Vehicle\Http\Requests;

use App\Modules\UserProfile\B2B\Services\B2bContext;
use App\Modules\UserProfile\Vehicle\Support\VehicleRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Which set of rules applies — company vehicles carry fields a private one
     * does not. Resolved from the context the user is acting in, so a
     * Privatkunde who is also a company member gets company rules only while
     * acting as that company.
     */
    private function isB2bContext(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        $userType = app(B2bContext::class)->effectiveUserType($user)->value;

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
