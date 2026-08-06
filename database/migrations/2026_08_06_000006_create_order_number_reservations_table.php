<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The allocation ledger behind `leasyback_orders.auftragsnummer`.
 *
 * Order references are derived from the registration number and the day, so
 * two orders for the same vehicle on the same date want the same value. The
 * unique index on `leasyback_orders.auftragsnummer` catches that, but only at
 * INSERT time — and one creation path (TÜV SÜD) puts the reference into an
 * outbound HTTP payload *before* the row is written, so "insert and retry on
 * conflict" would mean re-sending a booking.
 *
 * This table moves the claim earlier: a reference is reserved in its own
 * committed statement, and only then used. The unique index here is what makes
 * two concurrent creations pick different numbers; `leasyback_orders` keeps its
 * own unique index as the final authority.
 *
 * Rows are never deleted. A reservation whose order was never created burns
 * that number deliberately — reusing it later would hand a partner a reference
 * they may already have seen on a failed request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_number_reservations', function (Blueprint $table) {
            $table->id();

            // Matches leasyback_orders.auftragsnummer (string) so the two
            // columns compare without a cast.
            $table->string('reference')->unique();

            // The base the reference was derived from, i.e. plate + ymd. The
            // sequence scan is a lookup on this, not a LIKE over the whole
            // table.
            $table->string('reference_base')->index();

            $table->unsignedInteger('sequence');

            $table->uuid('vehicle_id')->nullable()->index();
            $table->unsignedBigInteger('reserved_by_user_id')->nullable();

            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_number_reservations');
    }
};
