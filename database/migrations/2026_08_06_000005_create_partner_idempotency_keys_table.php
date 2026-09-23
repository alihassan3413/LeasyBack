<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replay protection for future partner POSTs.
 *
 * A partner that times out and retries must not create a second order. The
 * key is scoped to the client, so two partners choosing the same UUID never
 * collide, and carries a hash of the request body: the same key with a
 * *different* payload is a client bug and is answered 409 rather than being
 * silently served the first response.
 *
 * `status` distinguishes an in-flight request from a finished one, so a retry
 * that arrives while the original is still running gets 409 (retry later)
 * instead of a half-written result. `locked_at` bounds that: a request that
 * died mid-flight releases its key after
 * config('partner_api.idempotency.lock_seconds') rather than wedging it
 * forever.
 *
 * Stored in the database rather than the cache because the guarantee has to
 * survive a cache flush — an evicted key means a duplicate order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_idempotency_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('partner_integration_client_id');
            $table->string('idempotency_key', 255);

            // "POST /api/v1/partner/orders" — the same key replayed against a
            // different endpoint is a conflict, not a cache hit.
            $table->string('endpoint', 191);

            // SHA-256 of the canonicalised request payload.
            $table->string('request_hash', 64);

            $table->string('status', 16)->default('in_progress');
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_body')->nullable();

            $table->timestampTz('locked_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampsTz();

            $table->foreign('partner_integration_client_id', 'partner_idempotency_client_id_foreign')
                ->references('id')->on('partner_integration_clients')->cascadeOnDelete();

            $table->unique(
                ['partner_integration_client_id', 'idempotency_key'],
                'partner_idempotency_client_key_unique'
            );
            $table->index('expires_at', 'partner_idempotency_expires_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_idempotency_keys');
    }
};
