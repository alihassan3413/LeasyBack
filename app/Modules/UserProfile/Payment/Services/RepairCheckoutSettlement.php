<?php

namespace App\Modules\UserProfile\Payment\Services;

use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Payment\Enums\PaymentPurpose;
use App\Modules\UserProfile\Payment\Enums\PaymentStatus;
use App\Modules\UserProfile\Payment\Models\LexwareInvoice;
use App\Modules\UserProfile\Payment\Models\OrderPayment;
use Illuminate\Support\Facades\Log;

class RepairCheckoutSettlement
{
    private const PAID = 'paid';

    public function __construct(private readonly PaymentService $payments) {}

    /**
     * @param  array<string, mixed>  $session
     */
    public function settle(string $type, array $session): ?OrderPayment
    {
        if ($type === 'checkout.session.completed' && (string) ($session['payment_status'] ?? '') !== self::PAID) {
            Log::info('Ignoring a Stripe checkout session that has not been paid.', [
                'session_id' => $session['id'] ?? null,
                'payment_status' => $session['payment_status'] ?? null,
            ]);

            return null;
        }

        $metadata = (array) ($session['metadata'] ?? []);

        if ((string) ($metadata['purpose'] ?? '') !== PaymentPurpose::Repair->value) {
            return null;
        }

        $payment = $this->resolvePayment($metadata, $session);

        if ($payment === null) {
            return null;
        }

        if (! $this->amountMatches($payment, $session)) {
            return null;
        }

        return $this->payments->transition($payment, PaymentStatus::Paid);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $session
     */
    private function resolvePayment(array $metadata, array $session): ?OrderPayment
    {
        $payment = OrderPayment::find((string) ($metadata['payment_id'] ?? ''));

        if ($payment === null) {
            return $this->refuse('no local repair payment', $metadata, $session);
        }

        if ($payment->purpose !== PaymentPurpose::Repair) {
            return $this->refuse('the payment is not a repair charge', $metadata, $session);
        }

        if ($payment->order_id !== (string) ($metadata['order_id'] ?? '')) {
            return $this->refuse('the payment does not belong to that order', $metadata, $session);
        }

        $order = LeasybackOrder::find($payment->order_id);

        if ($order === null || $order->vehicle_id !== (string) ($metadata['vehicle_id'] ?? '')) {
            return $this->refuse('the order does not belong to that vehicle', $metadata, $session);
        }

        $invoiceId = (string) ($metadata['lexware_invoice_id'] ?? '');

        if ($invoiceId !== '' && ! LexwareInvoice::where('order_id', $payment->order_id)
            ->where('lexware_invoice_id', $invoiceId)
            ->exists()) {
            return $this->refuse('the invoice does not belong to that order', $metadata, $session);
        }

        return $payment;
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function amountMatches(OrderPayment $payment, array $session): bool
    {
        $amount = (int) ($session['amount_total'] ?? 0);
        $currency = strtolower((string) ($session['currency'] ?? ''));

        if ($amount !== (int) $payment->amount_cents || $currency !== strtolower((string) $payment->currency)) {
            Log::warning('Refused a Stripe checkout session whose amount did not match the repair charge.', [
                'payment_id' => $payment->id,
                'session_id' => $session['id'] ?? null,
                'expected_amount_cents' => $payment->amount_cents,
                'observed_amount_cents' => $amount,
                'expected_currency' => $payment->currency,
                'observed_currency' => $currency,
            ]);

            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $session
     */
    private function refuse(string $reason, array $metadata, array $session): null
    {
        Log::warning('Refused a Stripe checkout session that did not match its order.', [
            'reason' => $reason,
            'session_id' => $session['id'] ?? null,
            'payment_id' => $metadata['payment_id'] ?? null,
            'order_id' => $metadata['order_id'] ?? null,
        ]);

        return null;
    }
}
