<?php

namespace App\Support;

/**
 * The one genuine channel difference in the repair-offer domain: who may see a
 * gross price.
 *
 * b2b.txt §9 is explicit — "Because this is a B2B portal, do not present prices
 * as gross prices". A company reclaims VAT, so gross is noise to it and the
 * quotation process is net throughout. A private customer pays the gross and
 * has a legal right to see it, so a B2C offer that showed only net would be
 * both useless and wrong.
 *
 * Every other difference people reach for — the tables, the snapshot, the
 * validity, the accept/reject pair — turned out not to be a difference at all.
 * This class exists so the one that is real has a single home, instead of a
 * `vehicle_belongs === 'B2B'` check in each layer that touches money.
 *
 * Amounts are strings and arithmetic is bcmath throughout, matching the rest of
 * the domain: these are money, and a float would drift.
 */
final class OfferPricingPolicy
{
    /**
     * Whether this channel's customer sees gross amounts at all.
     */
    public static function showsGross(bool $isB2b): bool
    {
        return ! $isB2b;
    }

    /**
     * The rate to stamp on an offer being created, or null where the channel
     * never shows gross — a B2B presentation carries no rate because there is
     * no gross amount for one to have produced.
     */
    public static function rateFor(bool $isB2b): ?string
    {
        return self::showsGross($isB2b) ? self::vatRate() : null;
    }

    public static function vatRate(): string
    {
        return (string) config('offers.vat_rate', '0.19');
    }

    /**
     * Gross from net at a given rate, rounded half up to the cent.
     *
     * bcmul truncates, so adding half a cent before truncating to scale 2 is
     * what makes 100.005 → 100.01 rather than 100.00. Amounts here are never
     * negative — a saving is computed as a difference of two grosses, not by
     * grossing a negative — so half-up needs no away-from-zero handling.
     */
    public static function gross(?string $net, ?string $rate): ?string
    {
        if ($net === null || $rate === null) {
            return null;
        }

        return bcadd(bcmul($net, bcadd('1', $rate, 4), 4), '0.005', 2);
    }
}
