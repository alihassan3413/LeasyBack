<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gutachten (Vehicle Condition Appraisal): operations coordinate the
 * inspection site and the transport there. Both live on the order's existing
 * logistics row, next to its confirmed appointment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leasyback_order_logistics', function (Blueprint $table) {
            $table->string('inspection_site_name')->nullable();
            $table->string('inspection_site_address', 500)->nullable();
            $table->boolean('transport_confirmed')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('leasyback_order_logistics', function (Blueprint $table) {
            $table->dropColumn(['inspection_site_name', 'inspection_site_address', 'transport_confirmed']);
        });
    }
};
