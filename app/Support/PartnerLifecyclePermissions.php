<?php

namespace App\Support;

use App\Enums\OrderStatus;

/**
 * Which lifecycle transitions an external integration owns.
 *
 * `OrderStatus` says what a status *is* and `TransitionOrderStatus` says which
 * edges exist; neither says who may walk them. Without that third question a
 * valid status plus a valid edge reads as authorization, which is how an
 * inspection provider's API key came to be able to cancel an order or announce
 * a car ready for pickup.
 *
 * The Partner API already states the principle this restores — "Partners read
 * status; they do not write it" (PartnerApi\Http\Controllers\OrderController).
 * The inspection callbacks predate that module and are the one place status is
 * genuinely written from outside, so they get the narrowest exception that
 * still lets the integration report what it actually did.
 *
 * Deliberately not a second lifecycle: this adds no statuses, no edges and no
 * parallel graph. It is an allow-list layered over the canonical one.
 */
final class PartnerLifecyclePermissions
{
    /**
     * Set by the authenticating middleware, read by the controller. The
     * provider identity is whatever the presented secret proves — never a
     * value the caller supplied, or the allow-list would be self-service.
     */
    public const REQUEST_ATTRIBUTE = 'partner_provider';

    public const TUV_SUD = 'tuvsud';

    public const DEKRA = 'dekra';

    /**
     * An inspection provider owns exactly the states its own work produces:
     * it confirms the appointment it accepted, it completes the inspection it
     * performed, and it performs the follow-up inspection after a repair.
     *
     * Everything else belongs to someone else and stays refused — `workshop`
     * and `reworkshop` to the workshop, `delivered` to LeasyBack releasing the
     * car to the customer, `cancelled`/`discarded` to Admin, `order_placed` to
     * the booking itself, and every B2B-only status to the return process.
     *
     * @var array<string, list<string>>
     */
    private const OWNED = [
        self::TUV_SUD => [
            OrderStatus::Confirmed->value,
            OrderStatus::Inspected->value,
            OrderStatus::Reinspection->value,
        ],

        /*
         * DEKRA's live callback (POST /api/dekra/terminbestaetigung) parses a
         * Quittung into the DekraProcess tables and has never touched an
         * order's status — there is no order-lifecycle capability in that
         * integration to grant. Listed with nothing rather than omitted, so
         * the absence is a recorded decision instead of an oversight, and so
         * a future DEKRA lifecycle callback has an obvious place to declare
         * what it may do.
         */
        self::DEKRA => [],
    ];

    /**
     * Provider-facing names for the same three transitions, so an integration
     * can describe what happened instead of naming an internal status.
     *
     * The canonical values stay accepted alongside them: TÜV SÜD's live
     * contract sends `status=confirmed` today, and narrowing the allow-list
     * must not break a call the provider is entitled to make. New integrations
     * should prefer the event names — they survive an internal rename, which
     * a raw status does not.
     *
     * @var array<string, string>
     */
    private const EVENT_ALIASES = [
        'appointment_confirmed' => OrderStatus::Confirmed->value,
        'inspection_completed' => OrderStatus::Inspected->value,
        'reinspection_completed' => OrderStatus::Reinspection->value,
    ];

    /**
     * Translate what the provider sent into a canonical status, or null if it
     * is neither a known event name nor a real status.
     */
    public static function resolveStatus(string $requested): ?string
    {
        $requested = trim($requested);

        return self::EVENT_ALIASES[$requested]
            ?? OrderStatus::tryFrom($requested)?->value;
    }

    public static function owns(string $provider, string $status): bool
    {
        return in_array($status, self::ownedBy($provider), true);
    }

    /**
     * @return list<string>
     */
    public static function ownedBy(string $provider): array
    {
        return self::OWNED[$provider] ?? [];
    }
}
