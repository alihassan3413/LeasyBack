<?php

namespace App\Modules\UserProfile\Payment\Services;

use App\Modules\UserProfile\Offer\Models\LeasybackOffer;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Payment\Enums\PaymentPurpose;
use App\Modules\UserProfile\Payment\Enums\PaymentStatus;
use App\Modules\UserProfile\Payment\Models\OrderPayment;
use App\Modules\UserProfile\Payment\Models\OrderPaymentMethod;
use App\Support\RepairPaymentPresentation;

/**
 * Opens the repair charge when repairs are finished.
 *
 * The trigger is the `reinspection → delivered` transition, which is the only
 * point in the B2C graph where repairs are provably complete *and* held: B2C
 * has no `repair_completed` status (that is B2B-only), and `reinspection` means
 * the follow-up inspection was performed, not that it passed — the outcome is
 * the branch taken from it, and the other branch is `reworkshop`.
 */
class RepairPaymentService
{
    public function __construct(private readonly PaymentService $payments) {}

    /**
     * Open the repair payment for an order that has just reached `delivered`.
     *
     * Returns true when this took ownership of the customer's "ready for
     * pickup" mail, which the caller must then suppress — PaymentService sends
     * it when the payment settles instead, so exactly one goes out.
     */
    public function startForDeliveredOrder(LeasybackOrder $order, bool $isB2b): bool
    {
        if ($isB2b) {
            return false;
        }

        $existing = $this->payments->repairPaymentFor($order->id);

        if ($existing !== null) {
            return ! $existing->status->satisfiesReleaseGate();
        }

        $offer = $this->selectedOffer($order);
        $amountCents = $this->grossCents($offer);

        $payment = OrderPayment::create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'purpose' => PaymentPurpose::Repair,
            'amount_cents' => $amountCents,
            'currency' => (string) config('services.stripe.currency', 'eur'),
        ]);

        // Nothing to collect: the car is released without any Stripe object
        // ever existing, and the pickup mail goes out through the same single
        // notifier every other outcome uses.
        if ($amountCents === 0) {
            $this->payments->transition($payment, PaymentStatus::NotRequired);

            return true;
        }

        return true;
    }

    /**
     * How this order's repair charge should be presented right now.
     *
     * Callers that only need to *say* where an order stands ask here rather
     * than reading `order_status` alone, which for a B2C order held on an
     * unpaid repair would claim the car is collectable.
     */
    public function presentedStage(LeasybackOrder $order, bool $isB2b): string
    {
        return RepairPaymentPresentation::stageFor(
            $order->order_status,
            $this->payments->repairPaymentFor($order->id)?->status->value,
            $isB2b,
        );
    }

    public function selectedOffer(LeasybackOrder $order): ?LeasybackOffer
    {
        return LeasybackOffer::where('order_id', $order->id)
            ->where('offer_status', 'selected')
            ->first();
    }

    /**
     * The customer-facing gross of the accepted offer, in minor units.
     *
     * B2C is the channel that sees gross (OfferPricingPolicy), and Stripe takes
     * integers — so this is the one boundary where the domain's decimal strings
     * become cents, via bcmath rather than float arithmetic.
     */
    public function grossCents(?LeasybackOffer $offer): int
    {
        if ($offer === null) {
            return 0;
        }

        $gross = (string) ($offer->final_total_gross ?? '0');

        return (int) bcmul($gross, '100', 0);
    }

    public function mandateFor(LeasybackOrder $order): ?OrderPaymentMethod
    {
        return OrderPaymentMethod::where('order_id', $order->id)->first();
    }
}
