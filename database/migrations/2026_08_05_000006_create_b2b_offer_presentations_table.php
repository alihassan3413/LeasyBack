<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('b2b_offer_presentations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('offer_id')->unique();
            $table->uuid('order_id');
            $table->uuid('workshop_quotation_id')->nullable();

            $table->json('lines')->nullable();
            $table->decimal('appraisal_total_net', 10, 2)->default(0);
            $table->decimal('repair_total_net', 10, 2)->default(0);
            $table->decimal('saving_net', 10, 2)->default(0);

            $table->date('valid_until')->nullable();
            $table->text('customer_note')->nullable();
            $table->timestampTz('presented_at')->nullable();

            $table->timestampTz('rejected_at')->nullable();
            $table->foreignId('rejected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('customer_comment')->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->foreign('offer_id')->references('offer_id')->on('leasyback_offers')->cascadeOnDelete();
            $table->foreign('order_id')->references('id')->on('leasyback_orders')->cascadeOnDelete();
            $table->foreign('workshop_quotation_id')->references('id')->on('b2b_workshop_quotations')->nullOnDelete();
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('b2b_offer_presentations');
    }
};
