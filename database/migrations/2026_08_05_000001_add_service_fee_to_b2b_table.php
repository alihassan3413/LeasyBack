<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('b2b', function (Blueprint $table) {
            $table->decimal('service_fee_amount', 10, 2)->default(295.00)->after('vat_id');
            $table->date('service_fee_effective_from')->default('2026-01-01')->after('service_fee_amount');
        });

        DB::table('b2b')->update([
            'service_fee_amount' => 295.00,
            'service_fee_effective_from' => '2026-01-01',
        ]);
    }

    public function down(): void
    {
        Schema::table('b2b', function (Blueprint $table) {
            $table->dropColumn(['service_fee_amount', 'service_fee_effective_from']);
        });
    }
};
