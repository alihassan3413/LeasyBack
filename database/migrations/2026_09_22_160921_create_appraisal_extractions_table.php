<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appraisal_extractions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->text('auftragsnummer')->index();
            $table->uuid('source_document_id')->nullable();
            $table->string('status', 16)->default('pending');
            $table->string('source', 16)->nullable();
            $table->string('extractor_version', 64)->nullable();
            $table->string('input_sha256', 64)->nullable();
            $table->json('proposal')->nullable();
            $table->json('warnings')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('applied_at')->nullable();
            $table->timestampTz('discarded_at')->nullable();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('applied_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('discarded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->foreign('order_id')->references('id')->on('leasyback_orders')->cascadeOnDelete();
            $table->foreign('source_document_id')->references('id')->on('vehicle_report_documents')->nullOnDelete();
            $table->index(['order_id', 'status']);
            $table->index(['source_document_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appraisal_extractions');
    }
};
