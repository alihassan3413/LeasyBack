<?php

namespace App\Http\Middleware;

use App\Modules\UserProfile\Payment\Contracts\StripeGateway;
use App\Modules\UserProfile\Payment\Exceptions\StripeGatewayException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The signature is the only authentication a Stripe webhook has — there is no
 * session and no user behind it. Same fail-closed pattern as
 * VerifyDekraWebhookSignature: reject every request when the secret is not
 * configured, rather than falling back to anything.
 */
class VerifyStripeWebhookSignature
{
    public const EVENT_ATTRIBUTE = 'stripe_event';

    public function __construct(private readonly StripeGateway $stripe) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (empty(config('services.stripe.webhook_secret'))) {
            abort(503, 'This endpoint is not configured.');
        }

        $signature = $request->header('Stripe-Signature');

        if ($signature === null || $signature === '') {
            abort(401, 'Missing Stripe signature.');
        }

        try {
            $event = $this->stripe->constructWebhookEvent($request->getContent(), $signature);
        } catch (StripeGatewayException) {
            abort(401, 'Invalid Stripe signature.');
        }

        $request->attributes->set(self::EVENT_ATTRIBUTE, $event);

        return $next($request);
    }
}
