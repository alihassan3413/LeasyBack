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
        'delivered' => 'Abgeschlossen',
        'discarded' => 'Verworfen',
        'cancelled' => 'Storniert',
    ];

    public static function for(?string $status): string
    {
        if ($status === null) {
            return 'Unbekannt';
        }

        return self::LABELS[$status] ?? str_replace('_', ' ', $status);
    }
}
