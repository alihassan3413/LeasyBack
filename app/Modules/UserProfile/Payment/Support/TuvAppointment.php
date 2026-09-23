<?php

namespace App\Modules\UserProfile\Payment\Support;

use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use Carbon\CarbonImmutable;

/**
 * The booked TÜV inspection slot, and the 48-hour cancellation boundary.
 *
 * Read from the persisted order rather than from anything a request supplied,
 * and compared server-side — the browser's clock never decides whether a fee
 * is owed.
 */
final class TuvAppointment
{
    public const NOTICE_HOURS = 48;

    public static function for(LeasybackOrder $order): ?CarbonImmutable
    {
        return self::fromPayload($order->request_payload);
    }

    /**
     * Accepts the payload in either shape: the model's cast array, or the raw
     * JSON string a query-builder row carries.
     */
    public static function fromPayload(mixed $payload): ?CarbonImmutable
    {
        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }

        $termin = data_get($payload, 'besichtigungsort.termin');

        if (! is_string($termin) || trim($termin) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($termin);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * True when cancelling now falls inside the notice period.
     *
     * An order with no appointment on file has no slot to cancel late, and a
     * slot already in the past is a no-show rather than a cancellation — both
     * answer false, so neither can produce this fee.
     */
    public static function isLateCancellation(?CarbonImmutable $appointment, ?CarbonImmutable $now = null): bool
    {
        if ($appointment === null) {
            return false;
        }

        $now = $now ?? CarbonImmutable::now();

        return $appointment->greaterThan($now)
            && $now->diffInSeconds($appointment, false) < self::NOTICE_HOURS * 3600;
    }
}
