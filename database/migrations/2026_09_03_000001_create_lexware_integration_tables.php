<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lexware_contacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('contact_id')->unique()->constrained('contacts', 'contact_id')->cascadeOnDelete();
            $table->string('lexware_contact_id')->unique();
            $table->timestampsTz();
        });

        Schema::create('lexware_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->text('auftragsnummer')->index();
            $table->string('purpose', 32);
            $table->unique(['order_id', 'purpose']);

            $table->string('status', 32);
            $table->string('lexware_invoice_id')->nullable()->unique();
            $table->string('voucher_number')->nullable();
            $table->string('resource_uri')->nullable();
            $table->unsignedInteger('lexware_version')->nullable();
            $table->string('voucher_status', 32)->nullable();

            $table->uuid('document_id')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('invoiced_at')->nullable();
            $table->timestampTz('documented_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestampsTz();

            $table->foreign('order_id')->references('id')->on('leasyback_orders')->cascadeOnDelete();
            $table->foreign('document_id')->references('id')->on('vehicle_report_documents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lexware_invoices');
        Schema::dropIfExists('lexware_contacts');
    }
};
