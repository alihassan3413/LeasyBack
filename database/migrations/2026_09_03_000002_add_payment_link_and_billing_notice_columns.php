<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_payments', function (Blueprint $table) {
            $table->string('stripe_payment_link_id')->nullable()->unique()->after('currency');
            $table->string('stripe_payment_link_url', 2048)->nullable()->after('stripe_payment_link_id');
            $table->timestampTz('payment_link_created_at')->nullable()->after('stripe_payment_link_url');
        });

        Schema::table('lexware_invoices', function (Blueprint $table) {
            $table->timestampTz('billing_email_sent_at')->nullable()->after('documented_at');
        });
    }

    public function down(): void
    {
        Schema::table('lexware_invoices', function (Blueprint $table) {
            $table->dropColumn('billing_email_sent_at');
        });

        Schema::table('order_payments', function (Blueprint $table) {
            $table->dropUnique(['stripe_payment_link_id']);
            $table->dropColumn(['stripe_payment_link_id', 'stripe_payment_link_url', 'payment_link_created_at']);
        });
    }
};
