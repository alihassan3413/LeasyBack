<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One logical charge against an order — the repair total, or the cancellation
 * fee — and the unit the payment state machine actually works on.
 *
 * The state machine is keyed here rather than on a Stripe PaymentIntent because
 * two legitimate outcomes have no Stripe object at all: a repair that costs
 * nothing (`not_required`) and a fee owed by a customer who never stored a card
 * (`requires_manual_collection`). Anything keyed on an intent id cannot express
 * either, so intents resolve *into* this row, never the other way round.
 *
 * A charge may outlive several intents; those live in `order_payment_intents`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->text('auftragsnummer')->index();

            // 'repair' | 'cancellation_fee'
            $table->string('purpose', 32);

            /*
             * At most one of each purpose per order. This is what makes
             * double-charging structurally impossible rather than merely
             * unlikely — the same belt-and-braces reasoning as
             * leasyback_orders.active_vehicle_id and the one-selected-offer
             * index: the application check and the constraint answer the same
             * question, so bypassing the service cannot produce a second
             * charge either.
             */
            $table->unique(['order_id', 'purpose']);

            /*
             * Minor units, because this number only ever travels to Stripe.
             * The offer domain keeps money as decimal(12,2) strings and does
             * bcmath arithmetic; the conversion happens at one boundary on the
             * way in. Zero is a legal, meaningful value here: it is what
             * `not_required` costs.
             */
            $table->unsignedInteger('amount_cents')->default(0);
            $table->char('currency', 3)->default('eur');

            /*
             * pending | processing | requires_action | failed |
             * requires_manual_collection | paid | not_required | cancelled.
             * Plain varchar, same reasoning as the mandate's status column.
             */
            $table->string('status', 32)->default('pending')->index();

            /*
             * The last status a customer notification actually went out for.
             *
             * Without it, the charge job's synchronous Stripe response and the
             * webhook that follows it both observe "succeeded" and both send
             * the same email. Durable rather than in-memory so it survives the
             * process, and compared inside the same locked transaction that
             * writes `status`, so whichever observer arrives first wins and the
             * second is a no-op.
             */
            $table->string('notified_status', 32)->nullable();

            $table->unsignedInteger('intent_count')->default(0);

            /*
             * Automatic, off-session confirmations only. Customer- and
             * admin-initiated retries are deliberately not counted here: the
             * cap exists because re-attempting a declining card on a loop
             * attracts card-network penalties, which is a property of
             * unattended retries, not of a person choosing to try again.
             */
            $table->unsignedInteger('auto_confirmation_count')->default(0);

            $table->timestampTz('paid_at')->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->foreign('order_id')->references('id')->on('leasyback_orders')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_payments');
    }
};
