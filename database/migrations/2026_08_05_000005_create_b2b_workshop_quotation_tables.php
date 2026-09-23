<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('b2b_workshop_quotations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->text('auftragsnummer')->index();
            $table->string('token_hash', 64)->unique();
            $table->text('workshop_label');
            $table->text('invited_email')->nullable();
            $table->boolean('show_appraisal_amounts')->default(true);

            $table->text('company_name')->nullable();
            $table->text('contact_person')->nullable();
            $table->text('contact_email')->nullable();
            $table->text('contact_phone')->nullable();
            $table->date('earliest_repair_start')->nullable();
            $table->unsignedSmallInteger('processing_days')->nullable();
            $table->decimal('total_net', 10, 2)->nullable();
            $table->boolean('cannot_repair_for_amount')->default(false);
            $table->text('cannot_repair_note')->nullable();

            $table->timestampTz('expires_at');
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->foreign('order_id')->references('id')->on('leasyback_orders')->cascadeOnDelete();
            $table->index(['order_id', 'created_at']);
        });

        Schema::create('b2b_workshop_quotation_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('quotation_id');
            $table->uuid('appraisal_position_id');
            $table->decimal('amount_net', 10, 2)->nullable();
            $table->text('repair_method')->nullable();
            $table->boolean('not_repairable')->default(false);
            $table->timestampsTz();

            $table->foreign('quotation_id')->references('id')->on('b2b_workshop_quotations')->cascadeOnDelete();
            $table->foreign('appraisal_position_id')->references('id')->on('b2b_appraisal_positions')->cascadeOnDelete();
            $table->unique(['quotation_id', 'appraisal_position_id'], 'b2b_quotation_position_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('b2b_workshop_quotation_items');
        Schema::dropIfExists('b2b_workshop_quotations');
    }
};
