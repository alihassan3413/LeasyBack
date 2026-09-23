<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The payment method a customer stores as security when they book an appraisal,
 * and the evidence that they authorized it being charged while they are not
 * present. One row per order.
 *
 * Per order rather than per user, even though Stripe attaches the method to the
 * Customer: which card backed *this* order months ago is the question a dispute
 * asks, and a shared per-user row would have been overwritten by then.
 *
 * Deliberately separate from `order_payments`. This records permission to
 * charge; that records a charge. One order has one of the former and up to two
 * of the latter (the repair, and a cancellation fee), so collapsing them would
 * force one of the two to be duplicated or lost.
 *
 * Nothing here is card data. Stripe holds the card; this holds an opaque
 * reference plus the four display fields needed to render "Visa ···· 4242".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_payment_methods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id')->unique();
            $table->text('auftragsnummer')->index();

            $table->string('stripe_customer_id')->nullable();

            /*
             * Both are webhook resolution keys, and they are not
             * interchangeable: `setup_intent.*` events carry a SetupIntent,
             * while `payment_method.detached` carries only the PaymentMethod
             * and can be resolved no other way.
             *
             * payment_method_id is NOT unique: one saved card legitimately
             * backs every order the same customer books.
             */
            $table->string('setup_intent_id')->nullable()->index();
            $table->string('payment_method_id')->nullable()->index();

            // Display only — never used to identify or charge anything.
            $table->string('pm_brand', 32)->nullable();
            $table->string('pm_last4', 4)->nullable();
            $table->unsignedSmallInteger('pm_exp_month')->nullable();
            $table->unsignedSmallInteger('pm_exp_year')->nullable();

            /*
             * awaiting_method | saved | detached. A plain varchar, not an enum
             * or check constraint, for the same reason b2b_order_billing.
             * billing_status is one: a new state must not need an ALTER.
             */
            $table->string('status', 32)->default('awaiting_method')->index();

            /*
             * Set only after the server has retrieved the SetupIntent from
             * Stripe and checked its status, customer and metadata. A browser
             * reporting success is a hint, so `status = 'saved'` without this
             * timestamp is not a state any writer may produce.
             */
            $table->timestampTz('verified_at')->nullable();

            /*
             * The off-session mandate. Stripe requires evidence that the
             * customer agreed to be charged when absent, and "they ticked a
             * box" is not evidence unless the box's exact wording is
             * recoverable — hence the version and the hash of the rendered
             * text, not just a boolean. The ip/user-agent pair is what makes
             * it attributable.
             */
            $table->timestampTz('offsession_authorized_at')->nullable();
            $table->string('authorization_version', 32)->nullable();
            $table->char('authorization_text_hash', 64)->nullable();
            $table->string('authorized_ip', 45)->nullable();
            $table->text('authorized_user_agent')->nullable();

            /*
             * The cancellation-fee disclosure, accepted at booking — a
             * different moment and a different text from the mandate above,
             * which is why it is a second pair of columns rather than a reuse
             * of the first. It is also captured *before* any card exists,
             * which is what makes the fee owed by a customer who never
             * completed the payment step.
             */
            $table->timestampTz('fee_acknowledged_at')->nullable();
            $table->string('fee_acknowledgement_version', 32)->nullable();

            $table->text('last_error')->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->foreign('order_id')->references('id')->on('leasyback_orders')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_payment_methods');
    }
};
