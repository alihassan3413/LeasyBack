<?php

namespace App\Modules\UserProfile\Payment\Jobs;

use App\Models\LeasybackOrder;
use App\Modules\UserProfile\Payment\Contracts\StripeGateway;
use App\Modules\UserProfile\Payment\Enums\PaymentInitiator;
use App\Modules\UserProfile\Payment\Enums\PaymentStatus;
use App\Modules\UserProfile\Payment\Exceptions\StripeGatewayException;
use App\Modules\UserProfile\Payment\Models\OrderPayment;
use App\Modules\UserProfile\Payment\Models\OrderPaymentIntent;
use App\Modules\UserProfile\Payment\Services\PaymentService;
use App\Modules\UserProfile\Payment\Services\RepairPaymentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * The off-session repair charge.
 *
 * Queued so a slow or unreachable Stripe never holds up the status transition
 * that triggered it. The synchronous result is applied through PaymentService
 * like any other observation — the webhook is the other observer, and whichever
 * arrives first wins.
 */
class ChargeRepairAmount implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly string $paymentId) {}

    public function handle(
        StripeGateway $stripe,
        PaymentService $payments,
        RepairPaymentService $repairPayments,
    ): void {
        $payment = OrderPayment::find($this->paymentId);

        if ($payment === null || $payment->status->isTerminal()) {
            return;
        }

        $order = LeasybackOrder::find($payment->order_id);
        $mandate = $order === null ? null : $repairPayments->mandateFor($order);

        if ($order === null || $mandate?->isChargeableOffSession() !== true) {
            $payments->transition($payment, PaymentStatus::RequiresManualCollection);

            return;
        }

        // One automatic attempt, and the counter is raised before the call so a
        // crash mid-request cannot leave it looking un-attempted and be retried
        // into a second charge.
        if (! $payment->mayAutoConfirmAgain()) {
            return;
        }

        $sequence = $payment->intent_count + 1;
        $offer = $repairPayments->selectedOffer($order);

        $payment->forceFill([
            'intent_count' => $sequence,
            'auto_confirmation_count' => $payment->auto_confirmation_count + 1,
        ])->save();

        try {
            $result = $stripe->createPaymentIntent(
                customerId: (string) $mandate->stripe_customer_id,
                amountCents: $payment->amount_cents,
                currency: $payment->currency,
                paymentMethodId: $mandate->payment_method_id,
                confirm: true,
                offSession: true,
                idempotencyKey: sprintf('%s:repair:%d:1', $payment->id, $sequence),
                metadata: array_filter([
                    'order_id' => $order->id,
                    'offer_id' => $offer?->offer_id,
                    'auftragsnummer' => (string) $order->auftragsnummer,
                    'purpose' => 'repair',
                ]),
            );
        } catch (StripeGatewayException $e) {
            Log::warning('Off-session repair charge failed.', [
                'payment_id' => $payment->id,
                'auftragsnummer' => $order->auftragsnummer,
                'stripe_code' => $e->stripeCode,
                'card_error' => $e->isCardError,
            ]);

            $payments->transition($payment->fresh(), PaymentStatus::Failed);

            return;
        }

        $intent = OrderPaymentIntent::create([
            'payment_id' => $payment->id,
            'sequence' => $sequence,
            'payment_intent_id' => $result->id,
            'payment_method_id' => $result->paymentMethodId ?? $mandate->payment_method_id,
            'status' => $result->status,
            'confirmation_count' => 1,
            'last_initiator' => PaymentInitiator::System,
            'failure_code' => $result->failureCode,
            'last_error' => $result->failureMessage,
            'created_at_stripe' => $result->createdAt,
        ]);

        $payments->transition(
            $payment->fresh(),
            PaymentService::statusForStripeIntent($result->status),
            $intent,
            $result->hasSucceeded() ? ['settled_at' => now()] : [],
        );
    }
}
