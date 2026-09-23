<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leasyback_order_logistics', function (Blueprint $table) {
            $table->date('confirmed_repair_start_date')->nullable()->after('confirmed_collection_date');
            $table->unsignedSmallInteger('estimated_processing_days')->nullable()->after('confirmed_repair_start_date');
        });
    }

    public function down(): void
    {
        Schema::table('leasyback_order_logistics', function (Blueprint $table) {
            $table->dropColumn(['confirmed_repair_start_date', 'estimated_processing_days']);
        });
    }
};
