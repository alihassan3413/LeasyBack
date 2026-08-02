<?php

namespace App\Modules\UserProfile\B2B\Data;

use App\Enums\B2bPermission;
use App\Enums\B2bRole;
use App\Enums\B2bVehicleScope;

/**
 * One user's membership of one B2B company: the company they are acting as,
 * their role in it, what they may do, and which of the company's vehicles
 * they may see.
 *
 * A plain immutable value object rather than an Eloquent model — `user_b2b`
 * has a composite primary key, and the surrounding B2B module already works
 * in query-builder rows (see B2BService). Building this from a row keeps the
 * authorization logic in one typed place instead of spread across `->role ===
 * 'owner'` string comparisons at the call sites.
 */
final class B2bMembership
{
    /**
     * @param  array<string, mixed>  $company  Minimal company header: b2b_id, company_name, logo_url.
     */
    private function __construct(
        public readonly int $userId,
        public readonly string $b2bId,
        public readonly string $companyName,
        public readonly ?string $companyLogoUrl,
        public readonly B2bRole $role,
        public readonly B2bVehicleScope $vehicleScope,
        public readonly B2bPermissionSet $permissions,
        public readonly array $company = [],
    ) {}

    /**
     * @param  object  $row  A joined user_b2b + b2b row.
     */
    public static function fromRow(object $row): self
    {
        $role = B2bRole::tryFrom((string) $row->role) ?? B2bRole::Member;

        return new self(
            userId: (int) $row->user_id,
            b2bId: (string) $row->b2b_id,
            companyName: (string) ($row->company_name ?? ''),
            companyLogoUrl: $row->logo_url ?? null,
            role: $role,
            // An owner is never scope-limited: they own every vehicle in the
            // company by definition, whoever happened to key it in.
            vehicleScope: $role === B2bRole::Owner
                ? B2bVehicleScope::All
                : (B2bVehicleScope::tryFrom((string) ($row->vehicle_scope ?? 'all')) ?? B2bVehicleScope::All),
            permissions: $role === B2bRole::Owner
                ? B2bPermissionSet::all()
                : B2bPermissionSet::fromRaw(self::decodePermissions($row->permissions ?? null)),
            company: [
                'b2b_id' => (string) $row->b2b_id,
                'company_name' => (string) ($row->company_name ?? ''),
                'logo_url' => $row->logo_url ?? null,
            ],
        );
    }

    public function isOwner(): bool
    {
        return $this->role === B2bRole::Owner;
    }

    /**
     * The single authorization question the whole app asks. Owners always
     * pass — there is no way to lock an owner out of their own company.
     */
    public function can(B2bPermission $permission): bool
    {
        return $this->isOwner() || $this->permissions->has($permission);
    }

    /** True when this member may only see vehicles they created themselves. */
    public function seesOwnVehiclesOnly(): bool
    {
        return $this->vehicleScope === B2bVehicleScope::Own;
    }

    /**
     * Shape handed to the frontend on every Inertia request, so the UI can
     * hide what the server would refuse anyway.
     *
     * @return array<string, mixed>
     */
    public function toSharedArray(): array
    {
        return [
            'b2b_id' => $this->b2bId,
            'company_name' => $this->companyName,
            'logo_url' => $this->companyLogoUrl,
            'role' => $this->role->value,
            'role_label' => $this->role->label(),
            'vehicle_scope' => $this->vehicleScope->value,
            'permissions' => $this->permissions->toArray(),
        ];
    }

    /**
     * `permissions` arrives as a JSON string from most drivers and as an
     * already-decoded array from some — normalise both.
     *
     * @return array<mixed>
     */
    private static function decodePermissions(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
