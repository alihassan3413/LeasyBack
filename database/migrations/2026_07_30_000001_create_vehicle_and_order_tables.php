<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Vehicles
        Schema::create('vehicles', function (Blueprint $table) {
            $table->uuid('vehicle_id')->primary();
            $table->string('license_plate')->unique();
            $table->date('first_registration_date')->nullable();
            $table->date('leasing_end_date')->nullable();
            $table->string('leasinggeber')->nullable();
            $table->string('vin', 17)->nullable();
            $table->string('make')->nullable();
            $table->string('model')->nullable();
            $table->uuid('b2b_id')->nullable();
            $table->foreignId('b2c_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('assigned_profile_id')->nullable();
            $table->string('vehicle_belongs', 10);
            $table->timestampsTz();
            $table->foreign('b2b_id')->references('b2b_id')->on('b2b')->cascadeOnDelete();
            $table->index('b2b_id');
            $table->index('b2c_user_id');
            $table->index('vehicle_belongs');
        });

        // Vehicle audit log
        Schema::create('vehicle_audit_log', function (Blueprint $table) {
            $table->uuid('log_id')->primary();
            $table->uuid('vehicle_id');
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 50);
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->timestampTz('changed_at')->useCurrent();
            $table->foreign('vehicle_id')->references('vehicle_id')->on('vehicles')->cascadeOnDelete();
            $table->index('vehicle_id');
            $table->index('changed_at');
        });

        // Vehicle documents
        Schema::create('vehicle_documents', function (Blueprint $table) {
            $table->uuid('document_id')->primary();
            $table->uuid('vehicle_id');
            $table->string('document_category', 50)->default('Fahrzeug');
            $table->string('document_type', 50);
            $table->string('original_file_name');
            $table->text('s3_key');
            $table->string('content_type')->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->foreign('vehicle_id')->references('vehicle_id')->on('vehicles')->cascadeOnDelete();
            $table->index('vehicle_id');
        });

        // Inspection stations
        Schema::create('inspection_stations', function (Blueprint $table) {
            $table->uuid('station_id')->primary();
            $table->string('provider', 50);
            $table->string('name');
            $table->string('strasse');
            $table->string('plz', 20);
            $table->string('ort', 100);
            $table->string('bundesland')->nullable();
            $table->string('land', 10)->default('de');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->index('provider');
            $table->index(['provider', 'is_active']);
        });

        // Leasyback orders (TÜV SÜD / others)
        Schema::create('leasyback_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('vehicle_id');
            $table->string('auftragsnummer')->unique();
            $table->string('leasyback_partner', 50);
            $table->string('order_status', 30)->default('order_placed');
            $table->json('request_payload');
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_body')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign('vehicle_id')->references('vehicle_id')->on('vehicles')->cascadeOnDelete();
            $table->index('vehicle_id');
            $table->index('order_status');
        });

        // Order confirmations
        Schema::create('leasyback_order_confirmations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('auftragsnummer');
            $table->timestampTz('confirmation_date');
            $table->string('confirmed_by_type', 20);
            $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('confirmed_by_name')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign('auftragsnummer')->references('auftragsnummer')->on('leasyback_orders')->cascadeOnDelete();
            $table->unique('auftragsnummer');
        });

        // Order status updates
        Schema::create('leasyback_order_status_updates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('auftragsnummer');
            $table->string('bewertung_id')->nullable();
            $table->string('old_status')->nullable();
            $table->string('new_status')->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('updated_by')->default('tuvsud_api_key');
            $table->string('auth_source', 20)->default('api_key');
            $table->string('caller_ip')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign('auftragsnummer')->references('auftragsnummer')->on('leasyback_orders')->cascadeOnDelete();
            $table->index(['auftragsnummer', 'created_at']);
        });

        // Order audit log
        Schema::create('leasyback_order_audit_log', function (Blueprint $table) {
            $table->uuid('log_id')->primary();
            $table->uuid('order_id')->nullable();
            $table->uuid('vehicle_id')->nullable();
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 30);
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->timestampTz('changed_at')->useCurrent();
            $table->foreign('order_id')->references('id')->on('leasyback_orders')->cascadeOnDelete();
            $table->foreign('vehicle_id')->references('vehicle_id')->on('vehicles')->cascadeOnDelete();
            $table->index('order_id');
            $table->index('vehicle_id');
            $table->index('changed_at');
        });

        // Offers
        Schema::create('leasyback_offers', function (Blueprint $table) {
            $table->uuid('offer_id')->primary();
            $table->uuid('order_id');
            $table->string('auftragsnummer');
            $table->unsignedSmallInteger('offer_sequence')->default(1);
            $table->string('offer_status', 20)->default('draft');
            $table->decimal('repair_cost_net', 12, 2)->default(0);
            $table->decimal('repair_cost_gross', 12, 2)->default(0);
            $table->decimal('depreciation_value_net', 12, 2)->default(0);
            $table->decimal('depreciation_value_gross', 12, 2)->default(0);
            $table->decimal('workshop_repair_quote_net', 12, 2)->default(0);
            $table->decimal('workshop_repair_quote_gross', 12, 2)->default(0);
            $table->decimal('missing_parts_cost_net', 12, 2)->default(0);
            $table->decimal('missing_parts_cost_gross', 12, 2)->default(0);
            $table->decimal('final_total_net', 12, 2)->default(0);
            $table->decimal('final_total_gross', 12, 2)->default(0);
            $table->text('additional_notes')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('selected_at')->nullable();
            $table->foreignId('selected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('closed_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->foreign('order_id')->references('id')->on('leasyback_orders')->cascadeOnDelete();
            $table->foreign('auftragsnummer')->references('auftragsnummer')->on('leasyback_orders')->cascadeOnDelete();
            $table->unique(['order_id', 'offer_sequence']);
            $table->index('auftragsnummer');
            $table->index('offer_status');
        });

        // Offer audit log
        Schema::create('leasyback_offer_audit_log', function (Blueprint $table) {
            $table->uuid('log_id')->primary();
            $table->string('auftragsnummer')->nullable();
            $table->uuid('offer_id')->nullable();
            $table->uuid('order_id')->nullable();
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 50);
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->timestampTz('changed_at')->useCurrent();
            $table->foreign('offer_id')->references('offer_id')->on('leasyback_offers')->cascadeOnDelete();
            $table->index('auftragsnummer');
            $table->index('offer_id');
            $table->index('changed_at');
        });

        // User workshops membership
        Schema::create('user_workshops', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workshop_id')->constrained('workshops', 'id')->cascadeOnDelete();
            $table->string('role', 50)->default('owner');
            $table->timestampsTz();
            $table->primary(['user_id', 'workshop_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_workshops');
        Schema::dropIfExists('leasyback_offer_audit_log');
        Schema::dropIfExists('leasyback_offers');
        Schema::dropIfExists('leasyback_order_audit_log');
        Schema::dropIfExists('leasyback_order_status_updates');
        Schema::dropIfExists('leasyback_order_confirmations');
        Schema::dropIfExists('leasyback_orders');
        Schema::dropIfExists('inspection_stations');
        Schema::dropIfExists('vehicle_documents');
        Schema::dropIfExists('vehicle_audit_log');
        Schema::dropIfExists('vehicles');
    }
};
