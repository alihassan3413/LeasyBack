<?php

namespace App\Modules\UserProfile\Payment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Payment\Enums\PaymentPurpose;
use App\Modules\UserProfile\Payment\Services\OrderPaymentCheckout;
use App\Modules\UserProfile\Payment\Services\PaymentAuthorizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Settling one obligation by hand — a repair charge the automatic attempt could
 * not finish, or a cancellation fee.
 *
 * JSON rather than Inertia for the same reason PaymentMethodController is: the
 * browser needs a client secret to mount the Stripe element, and needs the
 * server's verdict before it may call the payment done. A redirect would
 * unmount the element mid-confirmation.
 *
 * Two things are never taken from the request. The PaymentIntent id is resolved
 * from the order's own obligation, so knowing another customer's intent id buys
 * nothing — there is no parameter to put it in. And the purpose comes from a
 * route default, not a payload, so a caller cannot point the repair endpoint at
 * a cancellation fee or the reverse.
 *
 * Authorization is owner-only and B2C-only (OrderPolicy::pay denies Admin
 * outright — authenticating a customer's card is not something anyone can do on
 * their behalf), and every refusal is a 404. Unlike storing a card, settling
 * does *not* require the order to still be open: a cancellation fee is by
 * definition owed on a cancelled one.
 */
class OrderPaymentController extends Controller
{
    public function __construct(
        private readonly PaymentAuthorizer $authorizer,
        private readonly OrderPaymentCheckout $checkout,
    ) {}

    /**
     * What is owed and whether the customer can act on it.
     */
    public function show(Request $request, string $orderId, string $purpose): JsonResponse
    {
        return response()->json(
            $this->checkout->state($this->resolve($request, $orderId), $this->purpose($purpose)),
        );
    }

    /**
     * The Stripe intent to finish — the existing one wherever Stripe allows it.
     */
    public function intent(Request $request, string $orderId, string $purpose): JsonResponse
    {
        $session = $this->checkout->prepare($this->resolve($request, $orderId), $this->purpose($purpose));

        return response()->json($session->toArray());
    }

    /**
     * Re-read the intent from Stripe after the browser confirmed it.
     *
     * Not the authority — the webhook is — but the two are the same operation
     * applied to the same observation, so whichever lands first settles the
     * payment and the other becomes a no-op.
     */
    public function sync(Request $request, string $orderId, string $purpose): JsonResponse
    {
        return response()->json(
            $this->checkout->sync($this->resolve($request, $orderId), $this->purpose($purpose)),
        );
    }

    private function resolve(Request $request, string $orderId): LeasybackOrder
    {
        $order = $this->authorizer->resolveOrderForSettlement($request->user(), $orderId);

        if ($order === null) {
            abort(404);
        }

        return $order;
    }

    private function purpose(string $purpose): PaymentPurpose
    {
        return PaymentPurpose::tryFrom($purpose) ?? abort(404);
    }
}
