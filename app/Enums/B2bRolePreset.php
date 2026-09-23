<?php

namespace App\Enums;

use App\Modules\UserProfile\B2B\Data\B2bPermissionSet;

/**
 * The three roles a company account offers its own people, as named in the
 * customer portal specification.
 *
 * A preset is a *shorthand*, not a new authorization mechanism: each one
 * resolves to the (B2bRole, B2bPermissionSet) pair the system already stores
 * and enforces, so nothing downstream — middleware, policies, scoping —
 * learns about presets at all. The fine-grained editor stays available for
 * companies that want something none of the three describes; such a member
 * simply matches no preset and is shown as "Individuell".
 *
 * These are roles *inside a customer company*. They have nothing to do with
 * UserType::Admin, which is LeasyBack staff.
 */
enum B2bRolePreset: string
{
    /**
     * Runs the company account: everything an operator can do, plus
     * approving quotations and administering the other members. Mapped to
     * the owner role, because administering members is what ownership *is*
     * — and an owner can never be locked out of their own company.
     */
    case CompanyAdministrator = 'company_administrator';

    /**
     * Day-to-day fleet work (b2b.txt §3 "Create orders"): adds and imports
     * vehicles, books services and creates orders, but commits the company to
     * nothing. Approving a quotation is a spend decision, so it stays with the
     * administrator.
     */
    case StandardUser = 'standard_user';

    /**
     * Sees the account, its statistics and exports (b2b.txt §3 "Read only");
     * changes nothing.
     */
    case ReadOnly = 'read_only';

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** What a newly invited member gets unless the inviter picks otherwise. */
    public static function default(): self
    {
        return self::StandardUser;
    }

    public function label(): string
    {
        return match ($this) {
            self::CompanyAdministrator => 'Unternehmens-Administrator',
            self::StandardUser => 'Standardnutzer',
            self::ReadOnly => 'Nur-Lese-Zugriff',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::CompanyAdministrator => 'Verwaltet das Unternehmenskonto: Aufträge anlegen, Angebote freigeben, Mitglieder einladen und Rechte vergeben.',
            self::StandardUser => 'Legt Fahrzeuge an, importiert Fahrzeuglisten, bucht Leistungen und legt Aufträge an. Kann keine Angebote freigeben und keine Mitglieder verwalten.',
            self::ReadOnly => 'Sieht Fahrzeuge, Aufträge, Firmendaten und Auswertungen und kann Daten exportieren, aber nichts anlegen oder freigeben.',
        };
    }

    /** The role this preset is stored as. */
    public function role(): B2bRole
    {
        return match ($this) {
            self::CompanyAdministrator => B2bRole::Owner,
            self::StandardUser, self::ReadOnly => B2bRole::Member,
        };
    }

    /**
     * The permissions this preset stores.
     *
     * An administrator is an owner, and an owner holds every permission
     * implicitly (B2bMembership::can()) — the full set is written anyway so
     * the stored row reads the same as the effective access.
     *
     * The standard user carries `vehicles.create` because b2b.txt §3 grants
     * the "Create orders" role "add individual vehicles" and "import
     * vehicles" — both gated on that one permission. Editing or deleting
     * vehicle data stays out.
     *
     * Read-only carries `analytics.view`: §3 lets that role view statistics
     * and export permitted data, and the statistics page and its export both
     * sit behind that permission.
     *
     * Changing a set here orphans memberships stored with the old one: they
     * keep the rights they were given and show as "Individuell" until the
     * preset is assigned to them again.
     */
    public function permissions(): B2bPermissionSet
    {
        return match ($this) {
            self::CompanyAdministrator => B2bPermissionSet::all(),
            self::StandardUser => B2bPermissionSet::fromRaw([
                B2bPermission::ViewCompany->value,
                B2bPermission::ViewVehicles->value,
                B2bPermission::CreateVehicles->value,
                B2bPermission::CreateOrders->value,
            ]),
            // "View orders/status" needs no permission of its own: an order is
            // a vehicle's process, and `orders.index` is gated on
            // `vehicles.view` for exactly that reason.
            self::ReadOnly => B2bPermissionSet::fromRaw([
                B2bPermission::ViewCompany->value,
                B2bPermission::ViewVehicles->value,
                B2bPermission::ViewAnalytics->value,
            ]),
        };
    }

    /**
     * Which preset a stored membership corresponds to, or null when its
     * rights were hand-picked and match none of them.
     *
     * Compared on the effective permission set rather than the raw JSON, so a
     * set stored before a preset gained a permission is reported as custom
     * rather than silently relabelled.
     *
     * An owner's effective set is always every permission (B2bMembership::
     * fromRow()), whatever happens to be stored — so an owner is always the
     * administrator, including an invitation or row written with a partial
     * list by the advanced editor.
     */
    public static function match(B2bRole $role, B2bPermissionSet $permissions): ?self
    {
        $effective = $role === B2bRole::Owner ? B2bPermissionSet::all() : $permissions;

        foreach (self::cases() as $preset) {
            if ($preset->role() === $role && $preset->permissions()->toArray() === $effective->toArray()) {
                return $preset;
            }
        }

        return null;
    }

    /** Label for a membership that matches no preset. */
    public static function customLabel(): string
    {
        return 'Individuell';
    }

    /**
     * The one label every surface shows for a (role, permissions) pair — team
     * page, company switcher, invitation page and email, admin customer view —
     * so the same person is never "Inhaber" in one place and
     * "Unternehmens-Administrator" in another.
     */
    public static function labelFor(B2bRole $role, B2bPermissionSet $permissions): string
    {
        return self::match($role, $permissions)?->label() ?? self::customLabel();
    }

    /**
     * The catalogue the member/invite dialog renders its role picker from.
     *
     * `assigns_owner` tells the UI which entry only an owner may hand out —
     * B2bMembershipService and B2bInvitationService refuse it server-side
     * either way.
     *
     * @return list<array<string, mixed>>
     */
    public static function options(): array
    {
        return array_map(fn (self $preset) => [
            'value' => $preset->value,
            'label' => $preset->label(),
            'description' => $preset->description(),
            'role' => $preset->role()->value,
            'permissions' => $preset->permissions()->toArray(),
            'assigns_owner' => $preset->role() === B2bRole::Owner,
        ], self::cases());
    }
}
