<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->unsignedInteger('mileage')->nullable()->after('model');
            $table->string('contract_number')->nullable()->after('mileage');
            $table->string('cost_centre')->nullable()->after('contract_number');
            $table->string('driver_name')->nullable()->after('cost_centre');
            $table->string('driver_contact')->nullable()->after('driver_name');
            $table->uuid('collection_address_profile_id')->nullable()->after('driver_contact');

            $table->foreign('collection_address_profile_id')
                ->references('id')->on('logistics_address_profiles')
                ->nullOnDelete();

            $table->index('collection_address_profile_id');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropForeign(['collection_address_profile_id']);
            $table->dropIndex(['collection_address_profile_id']);
            $table->dropColumn([
                'mileage',
                'contract_number',
                'cost_centre',
                'driver_name',
                'driver_contact',
                'collection_address_profile_id',
            ]);
        });
    }
};
