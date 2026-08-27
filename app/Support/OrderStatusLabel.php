<?php

namespace App\Support;

class OrderStatusLabel
{
    /**
     * @var array<string, string>
     */
    private const LABELS = [
        'order_requested' => 'Anfrage gesendet',
        'order_placed' => 'Bestellt',
        'confirmed' => 'Bestätigt',
        'inspected' => 'Geprüft',
        'workshop' => 'In Werkstatt',
        'reinspection' => 'Nachprüfung',
        'reworkshop' => 'Erneut in Werkstatt',
        // "Ready for collection", not "finished" — this is the status that
        // sends VehicleReadyForPickupMail. It read 'Abgeschlossen' here while
        // the frontend read 'Geliefert', and neither was what it means.
        'delivered' => 'Abholbereit',
        'vehicle_collected' => 'Fahrzeug abgeholt',
        'workshop_commissioned' => 'Werkstatt beauftragt',
        'repair_completed' => 'Reparatur abgeschlossen',
        'vehicle_returned' => 'Fahrzeug zurückgegeben',
        'invoice_processed' => 'Rechnung verarbeitet',
        'completed' => 'Abgeschlossen',
        'discarded' => 'Verworfen',
        'cancelled' => 'Storniert',
    ];

    /**
     * Wording for the derived stages that override a raw status.
     *
     * @var array<string, string>
     */
    private const STAGE_LABELS = [
        RepairPaymentPresentation::AWAITING => 'Zahlung erforderlich',
        RepairPaymentPresentation::PROCESSING => 'Zahlung wird verarbeitet',
    ];

    public static function for(?string $status): string
    {
        if ($status === null) {
            return 'Unbekannt';
        }

        return self::LABELS[$status] ?? str_replace('_', ' ', $status);
    }

    /**
     * What the customer should be told the order says, which for a held
     * repair charge is not the raw status: `delivered` on its own reads
     * "Abholbereit" while the completion gate is still refusing pickup.
     *
     * Mirrors getOrderStatusLabel() in lib/vehicleStatus.ts — same rule, same
     * two overrides, same single status they apply to — so a notification and
     * the page it links to cannot contradict each other.
     */
    public static function presented(?string $status, string $stage): string
    {
        if ($status === 'delivered' && isset(self::STAGE_LABELS[$stage])) {
            return self::STAGE_LABELS[$stage];
        }

        return self::for($status);
    }
}
