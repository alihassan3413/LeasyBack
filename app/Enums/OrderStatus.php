<?php

namespace App\Enums;

enum OrderStatus: string
{
    case OrderRequested = 'order_requested';
    case OrderPlaced = 'order_placed';
    case Confirmed = 'confirmed';
    case Discarded = 'discarded';
    case Cancelled = 'cancelled';
    case Inspected = 'inspected';
    case Workshop = 'workshop';
    case Reinspection = 'reinspection';
    case Delivered = 'delivered';
    case Reworkshop = 'reworkshop';

    /**
     * Get all valid order status values.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Statuses considered "active" (not yet delivered, discarded, or cancelled).
     *
     * @return array<string>
     */
    public static function activeValues(): array
    {
        return [
            self::OrderRequested->value,
            self::OrderPlaced->value,
            self::Confirmed->value,
            self::Inspected->value,
            self::Workshop->value,
            self::Reinspection->value,
            self::Reworkshop->value,
        ];
    }
}
