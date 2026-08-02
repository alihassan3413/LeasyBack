<?php

namespace App\Enums;

/**
 * Canonical lowercase document_type values shared by the admin uploader and
 * the customer timeline (resources/js/lib/customerOrderFlow.ts matches these
 * exact strings to place a document on a step).
 */
enum DocumentType: string
{
    case Gutachten = 'gutachten';
    case Nachgutachten = 'nachgutachten';
    case Rechnung = 'rechnung';
    case Leasingvertrag = 'leasingvertrag';
    case Vorschaden = 'vorschaden';
    case Sonstiges = 'sonstiges';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Gutachten => 'Gutachten',
            self::Nachgutachten => 'Nachgutachten',
            self::Rechnung => 'Rechnung',
            self::Leasingvertrag => 'Leasingvertrag',
            self::Vorschaden => 'Vorschaden',
            self::Sonstiges => 'Sonstiges',
        };
    }
}
