<?php

namespace App\Modules\UserProfile\Payment\Jobs;

use App\Modules\UserProfile\Payment\Models\OrderPayment;
use App\Modules\UserProfile\Payment\Services\OffSessionCharger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The off-session cancellation-fee charge.
 *
 * Queued for a reason that matters more here than it does for the repair
 * charge: the cancellation itself is already committed and irreversible by the
 * time this runs. Whatever Stripe says — a decline, a timeout, an outage —
 * cannot reach back and un-cancel an order the customer asked to cancel.
 */
class ChargeCancellationFee implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly string $paymentId) {}

    public function handle(OffSessionCharger $charger): void
    {
        $payment = OrderPayment::find($this->paymentId);

        if ($payment === null) {
            return;
        }

        $charger->charge($payment);
    }
}
