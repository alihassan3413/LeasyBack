<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per Stripe PaymentIntent, append-only.
 *
 * Stripe's own retry model is to re-confirm the *same* intent: a declined
 * off-session charge leaves the intent at `requires_payment_method`, which
 * exists precisely so it can be confirmed again, and a 3DS challenge leaves it
 * at `requires_action`, which must be completed on that exact intent. So a
 * retry is normally a re-confirmation recorded as `confirmation_count + 1`, and
 * a new row appears only when the previous intent is genuinely unusable —
 * `canceled`, or superseded because the amount changed.
 *
 * Either way nothing is overwritten. Every intent an order ever had keeps its
 * id, its sequence and its failure reason, which is what makes "why was this
 * customer charged twice / not at all" answerable months later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_payment_intents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('payment_id');

            $table->unsignedInteger('sequence');
            $table->unique(['payment_id', 'sequence']);

            /*
             * The webhook resolution key. Unique across the whole table, not
             * just within a payment: a `payment_intent.*` event carries this id
             * and nothing else useful, and resolving it to the wrong parent
             * would let a cancellation-fee event settle a repair charge.
             */
            $table->string('payment_intent_id')->unique();

            // Snapshot of what was actually charged, which may differ from the
            // mandate's current method if the customer changed cards.
            $table->string('payment_method_id')->nullable();

            // Mirrors Stripe's own intent status vocabulary, unmapped, so a
            // status this codebase has never seen is still recorded faithfully.
            $table->string('status', 32)->index();

            /*
             * Re-confirmations of THIS intent. Distinct from the parent's
             * `sequence`: a declined card that is retried three times is one
             * intent with confirmation_count 3, not three intents.
             */
            $table->unsignedInteger('confirmation_count')->default(0);

            // system | customer | admin — who triggered the last confirmation.
            // The cap on automatic retries only applies to `system`.
            $table->string('last_initiator', 16)->nullable();

            $table->string('failure_code', 64)->nullable();
            $table->text('last_error')->nullable();

            // Stripe's own creation timestamp, kept alongside ours so a clock
            // skew between the two systems is visible rather than confusing.
            $table->timestampTz('created_at_stripe')->nullable();
            $table->timestampTz('settled_at')->nullable();

            $table->timestampsTz();

            $table->foreign('payment_id')->references('id')->on('order_payments')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_payment_intents');
    }
};
