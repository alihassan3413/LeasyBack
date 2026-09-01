<?php

namespace App\Modules\UserProfile\Payment\Actions;

use App\Enums\OrderStatus;
use App\Models\LeasybackOrder as OrderRecord;
use App\Modules\UserProfile\Order\Actions\TransitionOrderStatus;
use App\Modules\UserProfile\Payment\Models\OrderPayment;
use Illuminate\Support\Facades\Log;

/**
 * Closes a B2C case once its €200 fee is settled.
 *
 * Reached only from PaymentService, and only on Paid, so every observer —
 * the charge job, the webhook, a manual sync — closes the case the same way
 * and none of them implements its own version. `completed` is terminal, so a
 * replay finds the order already closed and does nothing.
 */
class CloseCaseAfterFee
{
    public function __construct(private readonly TransitionOrderStatus $transitionOrderStatus) {}

    public function __invoke(OrderPayment $fee): void
    {
        $order = OrderRecord::find($fee->order_id);

        if ($order === null || in_array($order->order_status, OrderStatus::closedValues(), true)) {
            return;
        }

        try {
            $this->transitionOrderStatus->__invoke(
                $order,
                OrderStatus::Completed->value,
                'system',
                'b2c_fee_settled',
            );
        } catch (\Throwable $e) {
            Log::warning('Could not close a B2C case after its fee settled.', [
                'order_id' => $order->id,
                'payment_id' => $fee->id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
