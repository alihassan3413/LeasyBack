<?php

namespace App\Modules\UserProfile\Payment\Services;

use App\Models\LeasybackOrder as OrderRecord;
use App\Modules\UserProfile\Payment\Contracts\StripeGateway;
use App\Modules\UserProfile\Payment\Enums\PaymentInitiator;
use App\Modules\UserProfile\Payment\Enums\PaymentStatus;
use App\Modules\UserProfile\Payment\Exceptions\StripeGatewayException;
use App\Modules\UserProfile\Payment\Models\OrderPayment;
use App\Modules\UserProfile\Payment\Models\OrderPaymentIntent;
use App\Modules\UserProfile\Payment\Models\OrderPaymentMethod;
use Illuminate\Support\Facades\Log;

/**
 * One unattended charge against a stored card, for any kind of obligation.
 *
 * Extracted from the repair charge when the cancellation fee needed the same
 * thing: the same single attempt, the same automatic-retry cap, the same
 * `{payment}:{purpose}:{sequence}:{confirmation}` idempotency key, and the same
 * rule that a vanished mandate becomes RequiresManualCollection rather than a
 * failure. Two copies of that would have drifted, and the half that drifted
 * would have been the one charging people money.
 *
 * The purpose is read off the payment, so nothing here needs to know which kind
 * of obligation it is settling.
 */
class OffSessionCharger
{
    public function __construct(
        private readonly StripeGateway $stripe,
        private readonly PaymentService $payments,
    ) {}

    /**
     * Attempt `$payment` off-session, once.
     *
     * @param  array<string, string>  $metadata  Purpose-specific Stripe metadata, merged over the order's own.
     */
    public function charge(OrderPayment $payment, array $metadata = []): void
    {
        if ($payment->status->isTerminal()) {
            return;
        }

        $order = OrderRecord::find($payment->order_id);
        $mandate = $order === null ? null : OrderPaymentMethod::where('order_id', $order->id)->first();

        if ($order === null || $mandate?->isChargeableOffSession() !== true) {
            // Not a failure: nothing was sent to Stripe, and the obligation
            // stands. Someone collects it by hand, or the customer settles it
            // themselves from the portal.
            $this->payments->transition($payment, PaymentStatus::RequiresManualCollection);

            return;
        }

        // One automatic attempt, and the counter is raised before the call so a
        // crash mid-request cannot leave it looking un-attempted and be retried
        // into a second charge.
        if (! $payment->mayAutoConfirmAgain()) {
            return;
        }

        $sequence = $payment->intent_count + 1;

        $payment->forceFill([
            'intent_count' => $sequence,
            'auto_confirmation_count' => $payment->auto_confirmation_count + 1,
        ])->save();

        try {
            $result = $this->stripe->createPaymentIntent(
                customerId: (string) $mandate->stripe_customer_id,
                amountCents: $payment->amount_cents,
                currency: $payment->currency,
                paymentMethodId: $mandate->payment_method_id,
                confirm: true,
                offSession: true,
                idempotencyKey: sprintf('%s:%s:%d:1', $payment->id, $payment->purpose->value, $sequence),
                metadata: array_filter([
                    ...$metadata,
                    'order_id' => $order->id,
                    'auftragsnummer' => (string) $order->auftragsnummer,
                    'purpose' => $payment->purpose->value,
                ]),
            );
        } catch (StripeGatewayException $e) {
            Log::warning('Off-session charge failed.', [
                'payment_id' => $payment->id,
                'purpose' => $payment->purpose->value,
                'auftragsnummer' => $order->auftragsnummer,
                'stripe_code' => $e->stripeCode,
                'card_error' => $e->isCardError,
            ]);

            $this->payments->transition($payment->fresh(), PaymentStatus::Failed);

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

        $this->payments->transition(
            $payment->fresh(),
            PaymentService::statusForStripeIntent($result->status),
            $intent,
            $result->hasSucceeded() ? ['settled_at' => now()] : [],
        );
    }
}
