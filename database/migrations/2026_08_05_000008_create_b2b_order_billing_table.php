<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('b2b_order_billing', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id')->unique();
            $table->text('auftragsnummer')->index();

            $table->string('billing_status', 20)->default('pending')->index();
            $table->string('invoice_reference')->nullable();
            $table->uuid('invoice_document_id')->nullable();

            $table->timestampTz('processed_at')->nullable();
            $table->foreignId('processed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->foreign('order_id')->references('id')->on('leasyback_orders')->cascadeOnDelete();
            $table->foreign('invoice_document_id')->references('id')->on('vehicle_report_documents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('b2b_order_billing');
    }
};
