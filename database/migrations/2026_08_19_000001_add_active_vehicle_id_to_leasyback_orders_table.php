<?php

use App\Enums\OrderStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Database backing for "a vehicle has at most one active order".
     *
     * The obvious shape — a partial unique index on `vehicle_id` WHERE
     * order_status NOT IN (...) — would bake today's terminal statuses into
     * DDL. Adding a status later would silently make the constraint wrong in
     * whichever direction the new status leans, and nothing would fail loudly
     * enough to notice. So the predicate stays in PHP, where the status model
     * already lives: LeasybackOrder::saving() mirrors `vehicle_id` into
     * `active_vehicle_id` while OrderStatus::closedValues() does not cover the
     * status, and nulls it once it does. A plain unique index then enforces the
     * invariant with no status knowledge of its own.
     *
     * NULLs are distinct under a unique index in sqlite, Postgres and MySQL
     * alike, so any number of closed orders per vehicle coexist — this is the
     * same guarantee a partial index gives, with the predicate maintained in
     * one place instead of two.
     *
     * Existing duplicates are not rewritten. The oldest active order per
     * vehicle claims the slot; later ones — the output of the bug this closes —
     * keep a NULL claim, so they stay usable and stay visible for manual
     * cleanup rather than failing the deploy or being silently cancelled.
     */
    public function up(): void
    {
        Schema::table('leasyback_orders', function (Blueprint $table) {
            // Deliberately no foreign key: this column only ever mirrors
            // `vehicle_id`, which already carries one with ON DELETE CASCADE,
            // so a second constraint could add nothing but a table rebuild.
            $table->uuid('active_vehicle_id')->nullable()->after('vehicle_id');
        });

        $this->claimSlotForOldestActiveOrderPerVehicle();

        Schema::table('leasyback_orders', function (Blueprint $table) {
            $table->unique('active_vehicle_id');
        });
    }

    public function down(): void
    {
        Schema::table('leasyback_orders', function (Blueprint $table) {
            $table->dropUnique(['active_vehicle_id']);
            $table->dropColumn('active_vehicle_id');
        });
    }

    private function claimSlotForOldestActiveOrderPerVehicle(): void
    {
        $claimed = [];
        $ids = [];

        DB::table('leasyback_orders')
            ->whereNotIn('order_status', OrderStatus::closedValues())
            ->orderBy('created_at')
            ->orderBy('id')
            ->select(['id', 'vehicle_id'])
            ->each(function (object $order) use (&$claimed, &$ids) {
                if (isset($claimed[$order->vehicle_id])) {
                    return;
                }

                $claimed[$order->vehicle_id] = true;
                $ids[] = $order->id;
            });

        foreach (array_chunk($ids, 500) as $chunk) {
            DB::table('leasyback_orders')
                ->whereIn('id', $chunk)
                ->update(['active_vehicle_id' => DB::raw('vehicle_id')]);
        }
    }
};
