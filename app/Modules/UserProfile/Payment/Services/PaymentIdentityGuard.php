<?php

namespace App\Modules\UserProfile\Payment\Services;

use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use Illuminate\Support\Facades\Log;

/**
 * "Is this Stripe object consistent with the order we resolved it to?"
 *
 * The half of payment authorization that works without an authenticated user,
 * and therefore the only one a webhook can use. A webhook's authentication is
 * its signature; this is its *authorization*.
 *
 * The rule that makes it worth having: every expected value is derived from the
 * **persisted order** — order → vehicle → b2c_user_id → that user's
 * stripe_customer_id — and never from the event, and never from
 * `$request->user()`. An event that names an order is therefore checked against
 * what that order actually is, so a forged or misrouted event cannot introduce
 * its own idea of who it belongs to.
 *
 * The authenticated confirm endpoint runs the same checks against the same
 * derived values, so the two paths cannot drift apart and a hole opened in one
 * does not stay closed in the other.
 */
class PaymentIdentityGuard
{
    public function __construct(private readonly PaymentAuthorizer $authorizer) {}

    /**
     * Whether a Stripe object may be applied to this order.
     *
     * @param  string|null  $eventCustomerId  The event's `customer`.
     * @param  array<string, string>  $metadata  The event's `metadata`.
     */
    public function permits(
        LeasybackOrder $order,
        ?string $eventCustomerId,
        array $metadata,
        string $context = 'stripe.event',
    ): bool {
        $refusal = $this->refusalReason($order, $eventCustomerId, $metadata);

        if ($refusal === null) {
            return true;
        }

        /*
         * Logged rather than thrown. A webhook that fails this has to be
         * acknowledged with 200 and dropped: answering with an error would put
         * Stripe into a retry loop over an event we will never accept, and
         * every redelivery would log the same refusal again.
         */
        Log::warning('Refused a Stripe object that did not match its order.', [
            'context' => $context,
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'reason' => $refusal,
        ]);

        return false;
    }

    /**
     * Why the object was refused, or null if it was not.
     *
     * Split out so the authenticated endpoint can turn a reason into a
     * validation message while the webhook only needs the boolean.
     *
     * @param  array<string, string>  $metadata
     */
    public function refusalReason(
        LeasybackOrder $order,
        ?string $eventCustomerId,
        array $metadata,
    ): ?string {
        if (! $this->authorizer->isB2cOrder($order)) {
            return 'order is not B2C';
        }

        $owner = $this->authorizer->ownerOf($order);

        if ($owner === null) {
            return 'order has no resolvable B2C owner';
        }

        /*
         * Checked before the comparison below, not folded into it: a null
         * expected customer would otherwise make any event's customer "not
         * equal" for the wrong reason, hiding a mandate whose Stripe customer
         * was never recorded behind a message about a mismatch.
         */
        if (empty($owner->stripe_customer_id)) {
            return 'order owner has no Stripe customer';
        }

        if ($eventCustomerId !== null && $eventCustomerId !== $owner->stripe_customer_id) {
            return 'customer does not belong to the order owner';
        }

        $metadataOrderId = $metadata['order_id'] ?? null;

        if ($metadataOrderId !== null && $metadataOrderId !== $order->id) {
            return 'metadata.order_id names a different order';
        }

        $metadataUserId = $metadata['user_id'] ?? null;

        if ($metadataUserId !== null && $metadataUserId !== (string) $owner->id) {
            return 'metadata.user_id names a different user';
        }

        return null;
    }
}
