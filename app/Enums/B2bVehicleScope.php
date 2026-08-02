<?php

namespace App\Enums;

/**
 * Which of the company's vehicles a member may see, given that they have
 * B2bPermission::ViewVehicles at all.
 *
 * A separate axis from permissions on purpose: "may view vehicles" and "may
 * view *everyone's* vehicles" are different questions, and collapsing them
 * into two permissions would make every future vehicle permission need an
 * all/own twin. This is applied once, centrally, in VehicleScopeService — so
 * every listing, detail page, document, order and policy inherits it.
 */
enum B2bVehicleScope: string
{
    case All = 'all';
    case Own = 'own';

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::All => 'Alle Fahrzeuge des Unternehmens',
            self::Own => 'Nur selbst angelegte Fahrzeuge',
        };
    }
}
