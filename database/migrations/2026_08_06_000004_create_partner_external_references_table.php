<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The partner's own identifiers for our records — `external_vehicle_id`,
 * `external_order_id`, and whatever a later partner needs.
 *
 * A side table rather than columns on `vehicles` and `leasyback_orders`,
 * because the mapping is per *partner*: two integrations may each have their
 * own id for the same vehicle, and neither may see the other's. Adding a
 * nullable column per partner per entity would not survive the second
 * partner, and would put partner data inside tables B2C shares.
 *
 * Both directions are unique per client, which is what makes an external id
 * usable as a lookup key and as an idempotent create guard:
 *   - one external id maps to at most one internal record,
 *   - one internal record has at most one external id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_external_references', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('partner_integration_client_id');

            // Entity kind, e.g. 'vehicle' or 'order'. A short domain word, not
            // a model class: the storage layer must not pin the mapping to a
            // PHP namespace that may be refactored.
            $table->string('reference_type', 32);

            // The partner's identifier.
            $table->string('external_id', 191);

            // Our identifier. String, because the tables it points at use
            // UUID keys in some cases and bigints in others.
            $table->string('internal_id', 191);

            $table->timestampsTz();

            $table->foreign('partner_integration_client_id', 'partner_external_refs_client_id_foreign')
                ->references('id')->on('partner_integration_clients')->cascadeOnDelete();

            $table->unique(
                ['partner_integration_client_id', 'reference_type', 'external_id'],
                'partner_external_refs_external_unique'
            );
            $table->unique(
                ['partner_integration_client_id', 'reference_type', 'internal_id'],
                'partner_external_refs_internal_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_external_references');
    }
};
