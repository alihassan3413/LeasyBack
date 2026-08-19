<?php

return [
    /*
     * German standard VAT, as a decimal fraction.
     *
     * This is the domain's first server-side statement of the rate. It already
     * existed, but only in the browser: CreateOfferModal.vue's `VAT_RATE`
     * converts between the net and gross an admin types by hand. That is fine
     * for a typing aid and useless as a source of truth — nothing on the server
     * could derive a gross price, so every gross amount in the system was one
     * somebody keyed in.
     *
     * A quotation-backed offer derives its gross from the workshop's net, so
     * the rate has to live here. The rate in force when an offer is published
     * is copied onto the presentation, which is what keeps a published offer's
     * gross total stable if this value is ever changed.
     */
    'vat_rate' => env('OFFER_VAT_RATE', '0.19'),
];
