<?php

namespace App\Modules\UserProfile\Payment\Support;

/**
 * The exact German wording of the two payment consents.
 *
 * Single-sourced here because the text is not decoration: a SHA-256 of it is
 * stored against every mandate, so the string the customer read, the string the
 * server hashes, and the string a dispute is later shown must be the same
 * bytes. Duplicating it into a Vue component would let the displayed and the
 * recorded wording drift apart silently — and the recorded hash would then
 * attest to something nobody ever saw.
 *
 * Changing either string means bumping the matching version in
 * config/payments.php, so old mandates keep pointing at the text they were
 * actually given.
 */
final class PaymentConsentText
{
    /**
     * Shown at the payment-method step, next to the card field. This is the
     * permission to charge the stored method while the customer is absent.
     */
    public static function offSessionAuthorization(): string
    {
        return 'Ich autorisiere LeasyBack, die nach Abschluss der Reparatur fällige '
            .'Reparatursumme sowie eine etwaige Stornogebühr von '
            .self::cancellationFeeLabel()
            .' von der hinterlegten Zahlungsmethode einzuziehen.';
    }

    /**
     * Shown at booking, before any card exists. This is what makes the
     * cancellation fee owed by a customer who never completed the payment
     * step — which is precisely the case the fee flow has to handle.
     */
    public static function cancellationFeeAcknowledgement(): string
    {
        return 'Ich habe die Information gelesen und akzeptiere, dass bei einer '
            .'Stornierung durch mich eine Stornogebühr von '
            .self::cancellationFeeLabel()
            .' anfällt.';
    }

    /**
     * The fee as German currency, from config — so the disclosed amount and
     * the charged amount cannot disagree.
     */
    public static function cancellationFeeLabel(): string
    {
        $cents = (int) config('payments.cancellation_fee_cents');

        return number_format($cents / 100, 2, ',', '.').' €';
    }
}
