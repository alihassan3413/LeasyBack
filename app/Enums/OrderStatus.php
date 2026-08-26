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
     * `completed` is deliberately absent: it is the successful terminal of
     * both channels now that a B2C case can genuinely close. What stays
     * B2B-only is the billing gate in front of it (see
     * TransitionOrderStatus::guardBillingBeforeCompletion), not the status.
     *
     * @return array<string>
     */
    public static function b2bOnlyValues(): array
    {
        return [
            self::VehicleCollected->value,
            // `workshop_commissioned` used to be listed here. It is not a B2B
            // fact: it means "the workshop has been instructed", which happens
            // in both channels the moment a customer's accepted offer is acted
            // on. What differs is only who moves the car afterwards.
            self::RepairCompleted->value,
            self::VehicleReturned->value,
            self::InvoiceProcessed->value,
        ];
    }

    /**
     * Statuses that only a B2C order may ever hold. `delivered` is the point
     * where a private customer's car is ready to collect from the workshop —
     * B2B has no equivalent, because there LeasyBack moves the vehicle itself.
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
     * Statuses that close an order successfully.
     *
     * `completed` is now the single successful terminal in both channels.
     * `delivered` used to be listed here as the B2C one, which was wrong in a
     * way that mattered: it means "ready for collection", so a car still
     * standing at the workshop counted as a finished case — it released the
     * vehicle's active-order claim and let a second order be booked for a car
     * the customer had not picked up yet.
     *
     * @return array<string>
     */
    public static function completedValues(): array
    {
        return [
            self::Completed->value,
        ];
    }

    /**
     * Statuses that close an order for good, in either channel — nothing is
     * pending from the customer, the workshop, an inspector or LeasyBack.
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

    /**
     * Whether a customer may still cancel their own order at this point.
     *
     * Stricter than "not closed", and deliberately so. `delivered` means the
     * repairs are finished, the workshop has been instructed and paid, and the
     * repair charge has already been opened — the service was delivered in
     * full. Offering to cancel there would take a €200 fee for undoing nothing,
     * on top of a repair the customer already owes.
     *
     * `vehicle_returned` and `invoice_processed` are the B2B equivalents. They
     * cannot be reached by a B2C order and cancellation is B2C-only, so they
     * are named here for completeness rather than because either can occur.
     */
    public static function isCustomerCancellable(?string $status): bool
    {
        if ($status === null) {
            return false;
        }

        return ! in_array($status, [
            ...self::closedValues(),
            self::Delivered->value,
            self::VehicleReturned->value,
            self::InvoiceProcessed->value,
        ], true);
    }
}
