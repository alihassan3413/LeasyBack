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
    case VehicleCollected = 'vehicle_collected';
    case WorkshopCommissioned = 'workshop_commissioned';
    case RepairCompleted = 'repair_completed';
    case VehicleReturned = 'vehicle_returned';
    case InvoiceProcessed = 'invoice_processed';
    case Completed = 'completed';

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
     * Statuses that only a B2B return order may ever hold.
     *
     * @return array<string>
     */
    public static function b2bOnlyValues(): array
    {
        return [
            self::VehicleCollected->value,
            self::WorkshopCommissioned->value,
            self::RepairCompleted->value,
            self::VehicleReturned->value,
            self::InvoiceProcessed->value,
            self::Completed->value,
        ];
    }

    /**
     * Statuses that only a B2C order may ever hold. `delivered` is the B2C
     * "ready for customer pickup" terminal; a B2B order ends at `completed`.
     *
     * @return array<string>
     */
    public static function b2cOnlyValues(): array
    {
        return [
            self::Delivered->value,
            self::Reworkshop->value,
        ];
    }

    /**
     * Statuses that close an order successfully — `delivered` is the B2C
     * terminal, `completed` the B2B one. A subset of closedValues(), which
     * also covers the unsuccessful terminals.
     *
     * @return array<string>
     */
    public static function completedValues(): array
    {
        return [
            self::Delivered->value,
            self::Completed->value,
        ];
    }

    /**
     * Statuses that close an order for good, in either channel.
     *
     * @return array<string>
     */
    public static function closedValues(): array
    {
        return [
            ...self::completedValues(),
            self::Cancelled->value,
            self::Discarded->value,
        ];
    }

    /**
     * Statuses considered "active" (not yet closed).
     *
     * @return array<string>
     */
    public static function activeValues(): array
    {
        return array_values(array_diff(self::values(), self::closedValues()));
    }
}
