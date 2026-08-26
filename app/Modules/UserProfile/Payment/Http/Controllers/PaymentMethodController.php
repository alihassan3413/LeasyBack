<?php

namespace App\Modules\UserProfile\Payment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\UserProfile\Payment\Services\PaymentAuthorizer;
use App\Modules\UserProfile\Payment\Services\PaymentMethodService;
use App\Modules\UserProfile\Payment\Support\PaymentConsentText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The customer-facing half of storing a payment method.
 *
 * Returns JSON rather than Inertia redirects because both endpoints are called
 * from inside a Stripe.js flow: the browser needs the client secret to mount
 * the card field, and needs to know whether the server accepted the result
 * before it may advance the wizard. A redirect would unmount the Stripe
 * element mid-confirmation.
 *
 * Both endpoints run PaymentAuthorizer first and 404 on any refusal, so a
 * non-owner cannot distinguish "no such order" from "not yours".
 */
class PaymentMethodController extends Controller
{
    public function __construct(
        private readonly PaymentAuthorizer $authorizer,
        private readonly PaymentMethodService $paymentMethods,
    ) {}

    /**
     * Open a SetupIntent so the browser can collect a card. Charges nothing.
     */
    public function createIntent(Request $request, string $orderId): JsonResponse
    {
        $user = $request->user();
        $order = $this->authorizer->resolveOrderFor($user, $orderId);

        if ($order === null) {
            abort(404);
        }

        $intent = $this->paymentMethods->startSetup($order, $user);

        return response()->json([
            'client_secret' => $intent->clientSecret,
            'setup_intent_id' => $intent->id,
            // Returned so the browser hashes and displays the same string the
            // server will record a hash of — the two must not be able to
            // disagree about what was agreed to.
            'authorization_text' => PaymentConsentText::offSessionAuthorization(),
        ]);
    }

    /**
     * Verify the SetupIntent server-side and store the mandate.
     *
     * The wizard advances on this response and on nothing else — a 422 leaves
     * the customer on the payment step, which is the point: the browser's own
     * report of success is not evidence.
     */
    public function confirm(Request $request, string $orderId): JsonResponse
    {
        $user = $request->user();
        $order = $this->authorizer->resolveOrderFor($user, $orderId);

        if ($order === null) {
            abort(404);
        }

        $validated = $request->validate([
            'setup_intent_id' => ['required', 'string', 'max:255'],
            // `accepted` rather than `boolean`: the off-session mandate is
            // only recorded if it was actually given, and a false here is a
            // refusal to consent, not a value to store.
            'offsession_authorized' => ['accepted'],
        ]);

        $mandate = $this->paymentMethods->confirmSetup(
            $order,
            $user,
            $validated['setup_intent_id'],
            [
                'authorization_text' => PaymentConsentText::offSessionAuthorization(),
                'ip' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 1000),
            ],
        );

        return response()->json([
            'status' => $mandate->status,
            'card' => [
                'brand' => $mandate->pm_brand,
                'last4' => $mandate->pm_last4,
                'exp_month' => $mandate->pm_exp_month,
                'exp_year' => $mandate->pm_exp_year,
            ],
        ]);
    }
}
