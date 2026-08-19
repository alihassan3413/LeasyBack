<?php

use App\Support\OneSelectedOfferPerOrder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Database backing for "an order has at most one selected offer".
     *
     * A partial unique index is the right shape here, where it was the wrong
     * one for the active-order invariant next door (see the 2026_08_19_000001
     * migration, which routes through a derived column instead). The
     * difference is what the predicate has to know: that one needed the whole
     * *set* of terminal statuses, which a new status would silently
     * invalidate, so the predicate had to live in PHP beside OrderStatus.
     * This one names a single literal — `selected` is the definition of the
     * invariant, not a snapshot of a list — so no future offer status can make
     * it wrong, and it can therefore sit in DDL where it also covers writers
     * that never touch a model: `selectOffer()` already closes sibling offers
     * with a query-builder mass update, and raw SQL, tinker and any future
     * bulk operation are all equally bound by it.
     *
     * Portability: sqlite (3.8.0+, so every build this app runs on) and
     * Postgres both support partial indexes with identical syntax. MySQL does
     * not support them at all; the deployment target is sqlite and the
     * secondary target Postgres, so the index is skipped there rather than
     * silently degraded into a plain unique index that would forbid a second
     * *closed* offer too. Same driver-guard shape as the case-insensitive
     * email index in 2026_07_31_000001.
     */
    public function up(): void
    {
        $this->assertNoOrderHasTwoSelectedOffers();

        if (! $this->driverSupportsPartialIndexes()) {
            return;
        }

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS '.OneSelectedOfferPerOrder::INDEX
            ." ON leasyback_offers (order_id) WHERE offer_status = 'selected'"
        );
    }

    public function down(): void
    {
        if (! $this->driverSupportsPartialIndexes()) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS '.OneSelectedOfferPerOrder::INDEX);
    }

    /**
     * Historical rows are never rewritten to fit the constraint: which of two
     * competing acceptances is the real customer decision is a commercial
     * question about money, not something a migration may answer by picking
     * one. If any order carries two, the deploy stops here and names them, so
     * the conflict is resolved deliberately and then re-run.
     */
    private function assertNoOrderHasTwoSelectedOffers(): void
    {
        $conflicted = DB::table('leasyback_offers')
            ->where('offer_status', 'selected')
            ->groupBy('order_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('order_id');

        if ($conflicted->isEmpty()) {
            return;
        }

        throw new RuntimeException(
            'Cannot enforce one selected offer per order: '.$conflicted->count()
            .' order(s) already hold more than one. Resolve them first, then re-run this migration. Order ids: '
            .$conflicted->implode(', ')
        );
    }

    private function driverSupportsPartialIndexes(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['sqlite', 'pgsql'], true);
    }
};
