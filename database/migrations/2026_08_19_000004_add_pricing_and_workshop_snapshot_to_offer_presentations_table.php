<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two things a quotation-backed offer has to freeze that the B2B-only
     * version never needed.
     *
     * `vat_rate` — a B2C customer is shown a gross total, derived from the
     * workshop's net. Deriving it at read time from today's configured rate
     * would silently restate what a customer already accepted the next time the
     * rate changed, so the rate in force at publication is copied onto the row
     * and the gross is derived from *that*. Null on a B2B presentation, which
     * has no gross amount for a rate to have produced.
     *
     * `workshop` — the identity of the workshop whose quotation the offer was
     * built from. `workshop_quotation_id` already points at the source, but it
     * is a live FK with nullOnDelete: it answers "which quotation" only for as
     * long as that row exists and says what it said. An accepted offer has to
     * stay a true record of who was going to do the work, so the name and
     * contact are snapshotted alongside the lines.
     */
    public function up(): void
    {
        Schema::table('b2b_offer_presentations', function (Blueprint $table) {
            $table->decimal('vat_rate', 6, 4)->nullable()->after('saving_net');
            $table->json('workshop')->nullable()->after('vat_rate');
        });
    }

    public function down(): void
    {
        Schema::table('b2b_offer_presentations', function (Blueprint $table) {
            $table->dropColumn(['vat_rate', 'workshop']);
        });
    }
};
