<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every vehicle of an order that covers several vehicles (Vehicle Condition
 * Appraisal brief: "Create one Vehicle Condition Appraisal Order containing
 * … every selected vehicle"). The order row keeps its first vehicle in
 * `leasyback_orders.vehicle_id`; this table lists all of them, the first
 * included (position 0).
 *
 * `active_vehicle_id` mirrors leasyback_orders.active_vehicle_id: filled
 * while the order is open, cleared when it closes (LeasybackOrder's saved
 * hook), and unique — so the database itself refuses one vehicle in two open
 * multi-vehicle orders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leasyback_order_vehicles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->uuid('vehicle_id');
            $table->uuid('active_vehicle_id')->nullable()->unique();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestampTz('created_at')->nullable();

            $table->unique(['order_id', 'vehicle_id']);
            $table->index('vehicle_id');
            $table->foreign('order_id')->references('id')->on('leasyback_orders')->cascadeOnDelete();
            $table->foreign('vehicle_id')->references('vehicle_id')->on('vehicles')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leasyback_order_vehicles');
    }
};