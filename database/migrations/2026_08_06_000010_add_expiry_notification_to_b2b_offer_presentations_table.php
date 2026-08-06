<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expiry is the one offer outcome with no writer.
 *
 * Acceptance, rejection, publication and withdrawal are all somebody pressing
 * a button; an offer expires because a date passed and nothing happened. §10.1
 * already treats that as a real state — an expired offer cannot be accepted —
 * but it is derived at read time from `valid_until`, so there is no moment to
 * hang an `offer.expired` event on.
 *
 * This column is that moment. The sweeper stamps it once when it first sees an
 * offer past its date, which is what makes the event exactly-once without
 * changing how expiry is decided anywhere else: every existing read still
 * compares `valid_until` and ignores this column entirely.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('b2b_offer_presentations', function (Blueprint $table) {
            $table->timestamp('expired_notified_at')->nullable()->after('last_reminder_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('b2b_offer_presentations', function (Blueprint $table) {
            $table->dropColumn('expired_notified_at');
        });
    }
};
