<?php

namespace App\Modules\UserProfile\Payment\Jobs;

use App\Models\LeasybackOrder;
use App\Modules\UserProfile\Payment\Models\OrderPayment;
use App\Modules\UserProfile\Payment\Services\OffSessionCharger;
use App\Modules\UserProfile\Payment\Services\RepairPaymentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The off-session repair charge.
 *
 * Queued so a slow or unreachable Stripe never holds up the status transition
 * that triggered it. The attempt itself lives in OffSessionCharger, shared with
 * the cancellation fee; all this adds is the accepted offer, which is what the
 * repair charge has that the fee does not.
 */
class ChargeRepairAmount implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly string $paymentId) {}

    public function handle(OffSessionCharger $charger, RepairPaymentService $repairPayments): void
    {
        $payment = OrderPayment::find($this->paymentId);

        if ($payment === null) {
            return;
        }

        $order = LeasybackOrder::find($payment->order_id);
        $offer = $order === null ? null : $repairPayments->selectedOffer($order);

        $charger->charge($payment, array_filter(['offer_id' => $offer?->offer_id]));
    }
}
