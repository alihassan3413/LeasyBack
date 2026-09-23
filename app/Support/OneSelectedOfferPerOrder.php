<?php

namespace App\Support;

/**
 * The name of the partial unique index that guarantees an order holds at most
 * one selected offer.
 *
 * It lives here because two places need to agree on it and neither can import
 * the other: the migration that creates it, and OfferService, which recognises
 * its violation to tell "another request decided this order first" apart from
 * any other unique conflict on the table. A migration class is anonymous by
 * convention in this app, so the constant cannot live there.
 */
final class OneSelectedOfferPerOrder
{
    public const INDEX = 'leasyback_offers_one_selected_per_order';
}
