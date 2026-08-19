<?php

use App\Enums\OrderStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * `delivered` stopped counting as a closed status, so the orders sitting in
     * it have to take their vehicle's active-order slot back.
     *
     * Nothing about those rows is being reinterpreted: `delivered` meant "ready
     * for collection" before this change and means exactly that after it. What
     * was wrong was treating it as closed — a car still standing at the
     * workshop released its vehicle for a new booking. This backfills the claim
     * that LeasybackOrder::saving() now derives, for rows written before the
     * rule changed.
     *
     * Down is not the inverse: leaving the claims in place on rollback is safe
     * (at worst a vehicle looks busy), whereas dropping them could let a
     * duplicate live order slip in during the window. The 000001 migration owns
     * that column and still removes it wholesale.
     */
    public function up(): void
    {
        $blocked = $this->vehiclesWhoseSlotIsAlreadyTaken();

        if ($blocked !== []) {
            throw new RuntimeException(
                'Cannot reclaim the active-order slot for orders awaiting pickup: '
                .count($blocked).' vehicle(s) hold a `delivered` order *and* a newer live one, '
                .'which the old closed-set allowed. Decide which order is real before re-running. Vehicle ids: '
                .implode(', ', $blocked)
            );
        }

        DB::table('leasyback_orders')
            ->where('order_status', OrderStatus::Delivered->value)
            ->whereNull('active_vehicle_id')
            ->update(['active_vehicle_id' => DB::raw('vehicle_id')]);
    }

    public function down(): void
    {
        // Intentionally empty — see the class docblock.
    }

    /**
     * A vehicle that already has a live order cannot also hand its slot to a
     * `delivered` one; the unique index would refuse the write, and picking a
     * winner is a question about a real car, not a migration's to answer.
     *
     * @return list<string>
     */
    private function vehiclesWhoseSlotIsAlreadyTaken(): array
    {
        $awaitingPickup = DB::table('leasyback_orders')
            ->where('order_status', OrderStatus::Delivered->value)
            ->pluck('vehicle_id')
            ->unique();

        if ($awaitingPickup->isEmpty()) {
            return [];
        }

        return DB::table('leasyback_orders')
            ->whereIn('vehicle_id', $awaitingPickup)
            ->whereNotNull('active_vehicle_id')
            ->pluck('vehicle_id')
            ->unique()
            ->values()
            ->all();
    }
};
