<?php

namespace App\Modules\UserProfile\Payment\Services;

use App\Modules\UserProfile\Order\Models\B2bOfferPresentation;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Payment\Contracts\StripeGateway;
use App\Modules\UserProfile\Payment\Enums\PaymentPurpose;
use App\Modules\UserProfile\Payment\Exceptions\StripeGatewayException;
use App\Modules\UserProfile\Payment\Models\LexwareInvoice;
use App\Modules\UserProfile\Payment\Models\OrderPayment;
use App\Services\Mail\OrderMailer;
use App\Support\OfferPricingPolicy;
use Illuminate\Support\Facades\DB;
use Throwable;

class RepairBillingWorkflow
{
    public function __construct(
        private readonly LexwareInvoiceWorkflow $invoices,
        private readonly PaymentService $payments,
        private readonly RepairPaymentService $repairPayments,
        private readonly OrderMailer $mailer,
    ) {}

    public function issueFor(LeasybackOrder $order, bool $isB2b): ?LexwareInvoice
    {
        if ($isB2b) {
            return null;
        }

        $invoice = $this->invoices->issueRepairInvoice($order, $isB2b);

        if ($invoice === null || ! $invoice->status->isSettled()) {
            return $invoice;
        }

        $payment = $this->payments->repairPaymentFor($order->id);

        if ($payment === null || $payment->amount_cents === 0) {
            return $invoice;
        }

        $payment = $this->ensurePaymentLink($payment, $invoice, $order);

        $this->sendBillingEmail($invoice, $payment, $order);

        return $invoice->fresh();
    }

    private function ensurePaymentLink(OrderPayment $payment, LexwareInvoice $invoice, LeasybackOrder $order): OrderPayment
    {
        if ($payment->stripe_payment_link_id !== null) {
            return $payment;
        }

        $this->assertAmountMatchesInvoice($payment, $order);

        $link = app(StripeGateway::class)->createPaymentLink(
            amountCents: (int) $payment->amount_cents,
            currency: (string) $payment->currency,
            productName: sprintf('Reparatur %s', $order->auftragsnummer),
            idempotencyKey: 'repair-payment-link:'.$payment->id,
            metadata: [
                'purpose' => PaymentPurpose::Repair->value,
                'payment_id' => (string) $payment->id,
                'order_id' => (string) $order->id,
                'auftragsnummer' => (string) $order->auftragsnummer,
                'vehicle_id' => (string) $order->vehicle_id,
                'lexware_invoice_id' => (string) $invoice->lexware_invoice_id,
                'voucher_number' => (string) $invoice->voucher_number,
            ],
        );

        DB::table('order_payments')
            ->where('id', $payment->id)
            ->whereNull('stripe_payment_link_id')
            ->update([
                'stripe_payment_link_id' => $link->id,
                'stripe_payment_link_url' => $link->url,
                'payment_link_created_at' => now(),
                'updated_at' => now(),
            ]);

        return $payment->fresh();
    }

    private function assertAmountMatchesInvoice(OrderPayment $payment, LeasybackOrder $order): void
    {
        $offer = $this->repairPayments->selectedOffer($order);
        $presentation = $offer === null ? null : B2bOfferPresentation::where('offer_id', $offer->offer_id)->first();

        if ($presentation === null || $presentation->vat_rate === null) {
            return;
        }

        $net = '0';

        foreach ((array) ($presentation->lines ?? []) as $line) {
            $line = (array) $line;

            if (($line['repair_amount_net'] ?? null) === null || (bool) ($line['not_repairable'] ?? false)) {
                continue;
            }

            $net = bcadd($net, (string) $line['repair_amount_net'], 2);
        }

        $invoicedCents = (int) bcmul(
            (string) OfferPricingPolicy::gross($net, (string) $presentation->vat_rate),
            '100',
            0,
        );

        if ($invoicedCents !== (int) $payment->amount_cents) {
            throw StripeGatewayException::apiError(sprintf(
                'The repair payment of %d cents does not match the invoiced total of %d cents.',
                $payment->amount_cents,
                $invoicedCents,
            ));
        }
    }

    private function sendBillingEmail(LexwareInvoice $invoice, OrderPayment $payment, LeasybackOrder $order): void
    {
        if ($payment->stripe_payment_link_url === null || $invoice->billing_email_sent_at !== null) {
            return;
        }

        $claimed = DB::table('lexware_invoices')
            ->where('id', $invoice->id)
            ->whereNull('billing_email_sent_at')
            ->update(['billing_email_sent_at' => now(), 'updated_at' => now()]) === 1;

        if (! $claimed) {
            return;
        }

        try {
            $this->mailer->repairInvoiceAvailable(
                $order,
                $order->vehicle,
                (string) $payment->stripe_payment_link_url,
                $invoice->voucher_number,
            );
        } catch (Throwable $exception) {
            DB::table('lexware_invoices')
                ->where('id', $invoice->id)
                ->update(['billing_email_sent_at' => null, 'updated_at' => now()]);

            throw $exception;
        }
    }
}
