<?php

namespace App\Modules\UserProfile\Payment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Payment\Services\PaymentAuthorizer;
use App\Modules\UserProfile\Payment\Services\RepairPaymentCheckout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Paying a repair charge that the automatic off-session attempt could not
 * finish.
 *
 * JSON rather than Inertia for the same reason PaymentMethodController is: the
 * browser needs a client secret to mount the Stripe element, and needs the
 * server's verdict before it may call the payment done. A redirect would
 * unmount the element mid-confirmation.
 *
 * Note that no endpoint here accepts a PaymentIntent id. The intent is always
 * resolved from the order's own repair payment, so knowing another customer's
 * intent id buys nothing — there is no parameter to put it in.
 *
 * PaymentAuthorizer runs first everywhere and every refusal is a 404: owner
 * only (OrderPolicy::pay denies Admin outright, because authenticating a
 * customer's card is not something anyone can do on their behalf), B2C only,
 * and the order must still be open.
 */
class RepairPaymentController extends Controller
{
    public function __construct(
        private readonly PaymentAuthorizer $authorizer,
        private readonly RepairPaymentCheckout $checkout,
    ) {}

    /**
     * What is owed and whether the customer can act on it.
     */
    public function show(Request $request, string $orderId): JsonResponse
    {
        return response()->json($this->checkout->state($this->resolve($request, $orderId)));
    }

    /**
     * The Stripe intent to finish — the existing one wherever Stripe allows it.
     */
    public function intent(Request $request, string $orderId): JsonResponse
    {
        $session = $this->checkout->prepare($this->resolve($request, $orderId));

        return response()->json($session->toArray());
    }

    /**
     * Re-read the intent from Stripe after the browser confirmed it.
     *
     * Not the authority — the webhook is — but the two are the same operation
     * applied to the same observation, so whichever lands first settles the
     * payment and the other becomes a no-op.
     */
    public function sync(Request $request, string $orderId): JsonResponse
    {
        return response()->json($this->checkout->sync($this->resolve($request, $orderId)));
    }

    private function resolve(Request $request, string $orderId): LeasybackOrder
    {
        $order = $this->authorizer->resolveOrderFor($request->user(), $orderId);

        if ($order === null) {
            abort(404);
        }

        return $order;
    }
}
