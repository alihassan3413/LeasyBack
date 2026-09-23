<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per partner *per environment*: the integration identity behind the
 * Partner API.
 *
 * A client binds exactly three things together and never more:
 *   - one B2B company (`b2b_id`) — the only company its tokens can ever reach,
 *   - one dedicated integration user (`user_id`) — the acting principal, so
 *     every write the partner makes is attributable and passes the same
 *     company permission checks a human member would,
 *   - one environment — sandbox and production are separate rows with
 *     separate tokens, not one row with a flag, so a sandbox credential is
 *     structurally incapable of reaching production data.
 *
 * `user_id` is unique: an integration user backs at most one client, so a
 * credential can never be pointed at a second company by adding a membership.
 * `(slug, environment)` is unique rather than `slug` alone, because the same
 * partner legitimately exists twice — once per environment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_integration_clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug', 64);
            $table->string('name');
            $table->string('environment', 16);
            $table->uuid('b2b_id');
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->string('contact_email')->nullable();

            // Per-client override of config('partner_api.rate_limit.per_minute').
            $table->unsignedInteger('rate_limit_per_minute')->nullable();

            $table->json('metadata')->nullable();
            $table->timestampsTz();

            $table->foreign('b2b_id')->references('b2b_id')->on('b2b')->restrictOnDelete();

            $table->unique(['slug', 'environment']);
            $table->index(['b2b_id', 'environment']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_integration_clients');
    }
};
