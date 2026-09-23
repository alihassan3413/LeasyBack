<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leasyback_order_logistics', function (Blueprint $table) {
            $table->date('requested_collection_date')->nullable()->after('delivery_same_as_pickup');
            $table->date('confirmed_collection_date')->nullable()->after('requested_collection_date');
            $table->text('internal_note')->nullable()->after('delivery_notes');
        });
    }

    public function down(): void
    {
        Schema::table('leasyback_order_logistics', function (Blueprint $table) {
            $table->dropColumn(['requested_collection_date', 'confirmed_collection_date', 'internal_note']);
        });
    }
};
