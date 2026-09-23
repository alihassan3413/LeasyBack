<?php

namespace App\Modules\UserProfile\Payment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\LeasybackOrder as OrderRecord;
use App\Modules\UserProfile\Payment\Actions\CancelOrderByCustomer;
use App\Modules\UserProfile\Payment\Services\PaymentAuthorizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A customer cancelling their own order.
 *
 * The *only* entry point that levies the cancellation fee. Admin's cancellation
 * runs through `admin.orders.status` and reaches the same status through the
 * same action, but never through here — which is why the fee cannot be charged
 * by accident when Admin cancels on a customer's behalf.
 *
 * Returns JSON so the confirmation dialog can report the fee's outcome without
 * a page transition; the caller reloads afterwards to pick up the new state.
 */
class OrderCancellationController extends Controller
{
    public function __construct(
        private readonly PaymentAuthorizer $authorizer,
        private readonly CancelOrderByCustomer $cancelOrder,
    ) {}

    public function store(Request $request, string $orderId): JsonResponse
    {
        $user = $request->user();
        $order = OrderRecord::find($orderId);

        // 404 on every refusal — missing, not theirs, B2B, or already closed —
        // matching how the rest of the payment surface answers.
        if ($order === null || ! $this->authorizer->allowsCancellation($user, $order)) {
            abort(404);
        }

        $fee = $this->cancelOrder->__invoke($order, $user, $request->ip());

        return response()->json([
            'order_status' => $order->fresh()?->order_status,
            'fee' => $fee === null ? null : [
                'amount_cents' => $fee->amount_cents,
                'amount' => $fee->amountDecimal(),
                'currency' => $fee->currency,
                'status' => $fee->fresh()?->status->value ?? $fee->status->value,
            ],
        ]);
    }
}
