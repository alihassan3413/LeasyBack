<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accident Damage (Unfallschaden), Phase 1.
 *
 * - company_billing_addresses: a company's reusable billing addresses, one of
 *   them marked as the default the order forms preselect.
 * - company_cost_centres: a company's reusable cost centres (name + number).
 * - leasyback_order_attachments: files stored with an order — the customer's
 *   supporting files now, the admin's final documents later (`kind`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_billing_addresses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('b2b_id');
            $table->string('name');
            $table->json('details');
            $table->boolean('is_default')->default(false);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->foreign('b2b_id')->references('b2b_id')->on('b2b')->cascadeOnDelete();
            $table->index(['b2b_id', 'is_default']);
        });

        Schema::create('company_cost_centres', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('b2b_id');
            $table->string('name');
            $table->string('number', 100)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->foreign('b2b_id')->references('b2b_id')->on('b2b')->cascadeOnDelete();
            $table->index('b2b_id');
        });

        Schema::create('leasyback_order_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->string('auftragsnummer');
            // customer_upload | final_document
            $table->string('kind', 30);
            $table->string('original_name');
            $table->string('path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['order_id', 'kind']);
            $table->index('auftragsnummer');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leasyback_order_attachments');
        Schema::dropIfExists('company_cost_centres');
        Schema::dropIfExists('company_billing_addresses');
    }
};