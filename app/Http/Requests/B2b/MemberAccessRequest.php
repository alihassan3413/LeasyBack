<?php

namespace App\Http\Requests\B2b;

use App\Enums\B2bPermission;
use App\Enums\B2bRole;
use App\Enums\B2bVehicleScope;
use App\Modules\UserProfile\B2B\Data\B2bPermissionSet;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The access-shape half of both the invite form and the member editor: role,
 * permission list and vehicle scope. Authorization is left to the route's
 * `b2b.can:members.manage` middleware and to B2bMembershipService's
 * owner-only rules — this class only validates shape.
 */
class MemberAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::in(B2bRole::assignableValues())],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in(B2bPermission::values())],
            'vehicle_scope' => ['required', 'string', Rule::in(B2bVehicleScope::values())],
        ];
    }

    public function role(): B2bRole
    {
        return B2bRole::from($this->validated('role'));
    }

    /**
     * Normalised through B2bPermissionSet so dependent permissions are
     * always stored together — see that class for why this isn't a rule.
     */
    public function permissions(): B2bPermissionSet
    {
        return B2bPermissionSet::fromRaw($this->validated('permissions'));
    }

    public function vehicleScope(): B2bVehicleScope
    {
        return B2bVehicleScope::from($this->validated('vehicle_scope'));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'role.required' => 'Bitte wählen Sie eine Rolle aus.',
            'role.in' => 'Bitte wählen Sie eine gültige Rolle aus.',
            'permissions.*.in' => 'Eine der gewählten Berechtigungen ist unbekannt.',
            'vehicle_scope.required' => 'Bitte wählen Sie aus, welche Fahrzeuge sichtbar sein sollen.',
            'vehicle_scope.in' => 'Bitte wählen Sie eine gültige Fahrzeug-Sichtbarkeit aus.',
        ];
    }
}
