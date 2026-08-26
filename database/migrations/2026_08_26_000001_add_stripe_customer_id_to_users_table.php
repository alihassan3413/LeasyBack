<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The customer's Stripe Customer id.
 *
 * On `users` rather than on the order, because Stripe attaches payment methods
 * to a Customer and a person books more than one order — creating a second
 * Customer for the same person would orphan the card they already saved and
 * bill them as a stranger. Which *mandate* backs a given order is a separate
 * fact, and lives on `order_payment_methods`.
 *
 * Unique: two users sharing a Stripe Customer would let one of them charge the
 * other's card, so the database refuses it rather than trusting every writer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('stripe_customer_id')->nullable()->unique()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['stripe_customer_id']);
            $table->dropColumn('stripe_customer_id');
        });
    }
};
