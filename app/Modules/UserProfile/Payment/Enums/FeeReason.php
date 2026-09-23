<?php

namespace App\Modules\UserProfile\Payment\Enums;

enum FeeReason: string
{
    case TuvLateCancellation = 'tuv_late_cancellation';
    case TuvNoShow = 'tuv_no_show';
    case RepairOfferRejected = 'repair_offer_rejected';
    case RepairOfferNoResponse = 'repair_offer_no_response';
    case RepairCancelledAfterAcceptance = 'repair_cancelled_after_acceptance';
    case RepairInactivity = 'repair_inactivity';

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
            self::TuvLateCancellation => 'Stornierung innerhalb von 48 Stunden',
            self::TuvNoShow => 'Termin nicht wahrgenommen',
            self::RepairOfferRejected => 'Reparaturangebot abgelehnt',
            self::RepairOfferNoResponse => 'Keine Rückmeldung zum Reparaturangebot',
            self::RepairCancelledAfterAcceptance => 'Reparatur nach Angebotsannahme abgebrochen',
            self::RepairInactivity => 'Reparatur nicht fortgeführt',
        };
    }
}
