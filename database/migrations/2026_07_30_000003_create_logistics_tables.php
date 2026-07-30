<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistics_address_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('owner_type', 10);
            $table->uuid('b2b_id')->nullable();
            $table->foreignId('b2c_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('profile_name');
            $table->json('details');
            $table->boolean('is_default')->default(false);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->foreign('b2b_id')->references('b2b_id')->on('b2b')->cascadeOnDelete();
        });

        Schema::create('leasyback_order_logistics', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('auftragsnummer')->unique();
            $table->uuid('pickup_profile_id')->nullable();
            $table->uuid('delivery_profile_id')->nullable();
            $table->json('pickup_details')->nullable();
            $table->json('delivery_details')->nullable();
            $table->boolean('delivery_same_as_pickup')->default(false);
            $table->text('pickup_notes')->nullable();
            $table->text('delivery_notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->foreign('auftragsnummer')->references('auftragsnummer')->on('leasyback_orders')->cascadeOnDelete();
            $table->foreign('pickup_profile_id')->references('id')->on('logistics_address_profiles')->nullOnDelete();
            $table->foreign('delivery_profile_id')->references('id')->on('logistics_address_profiles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leasyback_order_logistics');
        Schema::dropIfExists('logistics_address_profiles');
    }
};
