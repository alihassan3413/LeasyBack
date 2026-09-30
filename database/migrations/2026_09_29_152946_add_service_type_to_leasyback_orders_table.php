<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('leasyback_orders', function (Blueprint $table) {
            $table->string('service_type')
                ->default('leasingrueckgabe')
                ->index()
                ->after('order_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
         Schema::table('leasyback_orders', function (Blueprint $table) {
            $table->dropColumn('service_type');
        });
    }
};
