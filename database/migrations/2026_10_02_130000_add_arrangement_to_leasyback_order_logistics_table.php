<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unfallschaden: what operations arranged when scheduling the order —
 * an inspection, vehicle access or a collection (Accident Damage brief:
 * "The inspection, vehicle access or collection has been arranged and
 * confirmed"). The date is the existing confirmed_collection_date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leasyback_order_logistics', function (Blueprint $table) {
            $table->string('confirmed_arrangement', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('leasyback_order_logistics', function (Blueprint $table) {
            $table->dropColumn('confirmed_arrangement');
        });
    }
};