<?php

namespace App\Enums;

enum VehicleOwnerType: string
{
    case B2C = 'B2C';
    case B2B = 'B2B';

    /**
     * Get all valid vehicle_belongs values.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
