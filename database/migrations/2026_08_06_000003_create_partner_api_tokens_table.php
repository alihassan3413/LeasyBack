<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Long-lived bearer credentials for the Partner API.
 *
 * A dedicated table rather than Sanctum's `personal_access_tokens` for two
 * reasons. First, `config('sanctum.expiration')` is 24 hours application-wide:
 * a partner credential stored there would silently die every day, and raising
 * the global value would extend every human session token with it. Second,
 * blast radius — a row here is only ever accepted by the Partner API
 * middleware, so a leaked partner token cannot be replayed against the
 * `auth:sanctum` routes the web SPA uses, and vice versa.
 *
 * Only the SHA-256 hash is stored. The plaintext exists once, in the output
 * of `partner:provision` / `partner:token:rotate`, and is unrecoverable
 * afterwards.
 *
 * `revoked_at` is a column rather than a delete, so a revoked credential
 * still answers "when did this stop working, and when was it last used".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_api_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('partner_integration_client_id');
            $table->string('name');

            // SHA-256 hex digest of the plaintext token.
            $table->string('token_hash', 64)->unique();

            // Scope set: a list of PartnerAbility values, or ['*'].
            $table->json('abilities');

            $table->timestampTz('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();

            // Free-text operator attribution from the provisioning command —
            // never a users FK, because credentials are issued from the CLI.
            $table->string('issued_by')->nullable();

            $table->timestampsTz();

            $table->foreign('partner_integration_client_id', 'partner_api_tokens_client_id_foreign')
                ->references('id')->on('partner_integration_clients')->cascadeOnDelete();

            $table->index(['partner_integration_client_id', 'revoked_at'], 'partner_api_tokens_client_revoked_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_api_tokens');
    }
};
