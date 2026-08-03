<?php

namespace App\Enums;

/**
 * Every distinct thing a B2B company member can be granted.
 *
 * Owners implicitly hold all of these and are never stored with an explicit
 * list (see B2bMembership::can()). Members hold exactly the subset the owner
 * granted them, persisted as `user_b2b.permissions`.
 *
 * Adding a capability is a one-line change here plus the check at the call
 * site — the members UI, the invitation form and the permission editor are
 * all generated from cases(), so nothing else has to be touched.
 *
 * Naming is `resource.action`, and view permissions double as page access:
 * `page()` maps a permission to the route it unlocks in the navigation.
 */
enum B2bPermission: string
{
    case ViewVehicles = 'vehicles.view';
    case CreateVehicles = 'vehicles.create';
    case UpdateVehicles = 'vehicles.update';
    case UploadVehicleDocuments = 'vehicles.documents.upload';
    case DeleteVehicleDocuments = 'vehicles.documents.delete';

    case CreateOrders = 'orders.create';
    case SelectOffers = 'offers.select';

    case ViewCompany = 'company.view';
    case ManageCompany = 'company.manage';

    case ViewMembers = 'members.view';
    case ManageMembers = 'members.manage';

    case ViewAnalytics = 'analytics.view';

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The permission set a brand-new member starts with when the owner
     * doesn't customise anything: read-only access to the fleet.
     *
     * @return array<string>
     */
    public static function memberDefaults(): array
    {
        return [self::ViewVehicles->value];
    }

    public function label(): string
    {
        return match ($this) {
            self::ViewVehicles => 'Fahrzeuge ansehen',
            self::CreateVehicles => 'Fahrzeuge anlegen',
            self::UpdateVehicles => 'Fahrzeugdaten bearbeiten',
            self::UploadVehicleDocuments => 'Dokumente hochladen',
            self::DeleteVehicleDocuments => 'Dokumente löschen',
            self::CreateOrders => 'Aufträge/Termine anlegen',
            self::SelectOffers => 'Angebote auswählen',
            self::ViewCompany => 'Firmendaten ansehen',
            self::ManageCompany => 'Firmendaten bearbeiten',
            self::ViewMembers => 'Mitglieder ansehen',
            self::ManageMembers => 'Mitglieder verwalten & einladen',
            self::ViewAnalytics => 'Auswertungen ansehen',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ViewVehicles => 'Zugriff auf das Fahrzeug-Dashboard.',
            self::CreateVehicles => 'Neue Fahrzeuge für das Unternehmen registrieren.',
            self::UpdateVehicles => 'Bestehende Fahrzeugdaten ändern.',
            self::UploadVehicleDocuments => 'Dokumente zu Fahrzeugen hinzufügen.',
            self::DeleteVehicleDocuments => 'Hochgeladene Dokumente wieder entfernen.',
            self::CreateOrders => 'Rückgabetermine für Fahrzeuge buchen.',
            self::SelectOffers => 'Verbindlich ein Angebot annehmen.',
            self::ViewCompany => 'Firmenstammdaten einsehen.',
            self::ManageCompany => 'Firmenstammdaten und Logo ändern.',
            self::ViewMembers => 'Die Mitgliederliste des Unternehmens sehen.',
            self::ManageMembers => 'Mitglieder einladen, Rechte ändern und entfernen.',
            self::ViewAnalytics => 'Kennzahlen und Auswertungen je Mitglied sehen.',
        };
    }

    /** Grouping used to lay the permission editor out. */
    public function group(): string
    {
        return match ($this) {
            self::ViewVehicles, self::CreateVehicles, self::UpdateVehicles,
            self::UploadVehicleDocuments, self::DeleteVehicleDocuments => 'Fahrzeuge',
            self::CreateOrders, self::SelectOffers => 'Aufträge & Angebote',
            self::ViewCompany, self::ManageCompany => 'Unternehmen',
            self::ViewMembers, self::ManageMembers, self::ViewAnalytics => 'Team & Auswertungen',
        };
    }

    /**
     * Permissions that make no sense without another one also being granted.
     * Enforced server-side in B2bPermissionSet, and mirrored in the UI so the
     * owner sees why a box ticked itself.
     *
     * @return array<string>
     */
    public function requires(): array
    {
        return match ($this) {
            self::CreateVehicles, self::UpdateVehicles, self::UploadVehicleDocuments,
            self::DeleteVehicleDocuments, self::CreateOrders, self::SelectOffers => [self::ViewVehicles->value],
            self::ManageCompany => [self::ViewCompany->value],
            self::ManageMembers => [self::ViewMembers->value],
            default => [],
        };
    }

    /**
     * The route this permission unlocks, when it gates a whole page. Used to
     * build the member's navigation — a member never sees a nav entry for a
     * page the server would refuse them.
     */
    public function page(): ?string
    {
        return match ($this) {
            self::ViewVehicles => 'dashboard',
            // The company's master data is a section of "Mein Konto"; the
            // registration form under /onboarding/b2b is a one-time step and
            // not a page this permission unlocks.
            self::ViewCompany => 'profile.edit',
            self::ViewMembers => 'b2b.members.index',
            default => null,
        };
    }
}
