<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the commissioning email actually reached the workshop.
     *
     * Commissioning itself is an order-level fact and stays where it belongs —
     * `order_status` plus the audit row. This column answers a different and
     * strictly operational question: did the workshop hear about it? The two
     * are separate because a mail transport failure must not undo a real
     * commissioning, which means the system has to be able to say "commissioned
     * but not notified" out loud and let an admin resend.
     *
     * It lives on the presentation because that is where the workshop lives:
     * the row already holds the snapshot of who was going to do the work, and
     * already carries operational timestamps of the same kind
     * (`last_reminder_sent_at`, `rejected_at`).
     */
    public function up(): void
    {
        Schema::table('b2b_offer_presentations', function (Blueprint $table) {
            $table->timestampTz('workshop_notified_at')->nullable()->after('workshop');
        });
    }

    public function down(): void
    {
        Schema::table('b2b_offer_presentations', function (Blueprint $table) {
            $table->dropColumn('workshop_notified_at');
        });
    }
};
