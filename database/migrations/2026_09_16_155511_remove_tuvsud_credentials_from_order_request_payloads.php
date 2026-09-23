<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Removes the TÜV SÜD username and token from orders approved before
 * OrderService::approveOrder stopped persisting them.
 *
 * Approval used to write the full outgoing request — including its
 * `authentifizierung` block — back into `leasyback_orders.request_payload`,
 * a column served to customers. Only that block is removed: nothing in the
 * application reads it, while `auftrag`, `besichtigungsort` and the rest of the
 * payload stay exactly as stored. `updated_at` is deliberately not touched —
 * the order itself did not change.
 *
 * Rows are scanned in PHP rather than filtered with a JSON/LIKE operator so the
 * migration behaves identically on SQLite, MySQL and PostgreSQL (`json` columns
 * do not support LIKE there).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('leasyback_orders')
            ->select(['id', 'request_payload'])
            ->orderBy('id')
            ->chunkById(500, function ($orders) {
                foreach ($orders as $order) {
                    $payload = json_decode((string) $order->request_payload, true);

                    if (! is_array($payload) || ! array_key_exists('authentifizierung', $payload)) {
                        continue;
                    }

                    unset($payload['authentifizierung']);

                    DB::table('leasyback_orders')
                        ->where('id', $order->id)
                        ->update(['request_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
                }
            }, 'id');
    }

    /**
     * Irreversible by design: restoring credentials into a customer-visible
     * column is exactly what this migration exists to prevent.
     */
    public function down(): void
    {
        //
    }
};
