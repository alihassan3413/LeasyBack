<?php

namespace App\Http\Requests\B2b;

use App\Enums\B2bPermission;
use App\Enums\B2bRole;
use App\Enums\B2bRolePreset;
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
 *
 * A request may describe that shape either way:
 *
 *  - `preset` — one of the three named company roles. Role and permissions
 *    are derived from it and anything sent alongside them is ignored, so the
 *    stored rights always match the name the UI showed.
 *  - `role` + `permissions` — the advanced editor, unchanged.
 *
 * This is the only place presets exist. By the time the services see it the
 * request is the same (B2bRole, B2bPermissionSet, B2bVehicleScope) triple it
 * has always been.
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
            'preset' => ['nullable', 'string', Rule::in(B2bRolePreset::values())],
            // Required only when no preset is given: a preset carries both.
            'role' => ['required_without:preset', 'string', Rule::in(B2bRole::assignableValues())],
            // Present (possibly empty) when no preset is given: an empty
            // custom set is a legitimate choice in the advanced editor, and
            // `required` would reject `[]`. With a preset it is ignored.
            'permissions' => $this->filled('preset') ? ['nullable', 'array'] : ['present', 'array'],
            'permissions.*' => ['string', Rule::in(B2bPermission::values())],
            'vehicle_scope' => ['required', 'string', Rule::in(B2bVehicleScope::values())],
        ];
    }

    /** The named company role this request selected, or null for a custom set. */
    public function preset(): ?B2bRolePreset
    {
        $value = $this->validated('preset');

        return $value === null ? null : B2bRolePreset::from($value);
    }

    public function role(): B2bRole
    {
        return $this->preset()?->role() ?? B2bRole::from($this->validated('role'));
    }

    /**
     * Normalised through B2bPermissionSet so dependent permissions are
     * always stored together — see that class for why this isn't a rule.
     */
    public function permissions(): B2bPermissionSet
    {
        if ($preset = $this->preset()) {
            return $preset->permissions();
        }

        // Choosing "Inhaber" in the advanced editor is the administrator role
        // whatever boxes happened to be ticked — an owner holds everything.
        if ($this->role() === B2bRole::Owner) {
            return B2bPermissionSet::all();
        }

        return B2bPermissionSet::fromRaw($this->validated('permissions') ?? []);
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
            'preset.in' => 'Bitte wählen Sie eine gültige Rolle aus.',
            'role.required_without' => 'Bitte wählen Sie eine Rolle aus.',
            'role.in' => 'Bitte wählen Sie eine gültige Rolle aus.',
            'permissions.present' => 'Bitte wählen Sie die Berechtigungen aus.',
            'permissions.array' => 'Die Berechtigungen haben ein ungültiges Format.',
            'permissions.*.in' => 'Eine der gewählten Berechtigungen ist unbekannt.',
            'vehicle_scope.required' => 'Bitte wählen Sie aus, welche Fahrzeuge sichtbar sein sollen.',
            'vehicle_scope.in' => 'Bitte wählen Sie eine gültige Fahrzeug-Sichtbarkeit aus.',
        ];
    }
}
