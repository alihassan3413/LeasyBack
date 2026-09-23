<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leasyback_offers', function (Blueprint $table) {
            $table->decimal('vat_rate', 6, 4)->nullable()->after('final_total_gross');
        });

        $manualB2cOfferIds = DB::table('leasyback_offers as o')
            ->join('leasyback_orders as ord', 'ord.id', '=', 'o.order_id')
            ->join('vehicles as v', 'v.vehicle_id', '=', 'ord.vehicle_id')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('b2b_offer_presentations as p')
                    ->whereColumn('p.offer_id', 'o.offer_id');
            })
            ->where('v.vehicle_belongs', '!=', 'B2B')
            ->pluck('o.offer_id');

        if ($manualB2cOfferIds->isNotEmpty()) {
            DB::table('leasyback_offers')->whereIn('offer_id', $manualB2cOfferIds)->update(['vat_rate' => '0.1900']);
        }
    }

    public function down(): void
    {
        Schema::table('leasyback_offers', function (Blueprint $table) {
            $table->dropColumn('vat_rate');
        });
    }
};
