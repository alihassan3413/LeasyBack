<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provenance of everything imported from the Base44 export: one row per legacy
 * record, whatever became of it.
 *
 * - imported / linked: a V2 row exists (`linked` = an existing V2 row was reused).
 * - deduplicated: the record was folded into another one (`target_*` = survivor).
 * - archived: not importable into V2 as such; the original values live in
 *   `payload` so nothing is lost.
 * - skipped: dropped on purpose; `payload.reason` says why.
 *
 * The map is what makes an import idempotent (a legacy id is never imported
 * twice) and reversible (a batch is deleted by its map rows). It is meant to be
 * dropped once the migration has been signed off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_import_map', function (Blueprint $table) {
            $table->id();
            $table->string('entity', 40);
            $table->string('legacy_id', 191);
            $table->string('status', 20);
            $table->string('target_table', 64)->nullable();
            $table->string('target_id', 64)->nullable();
            $table->string('source_hash', 64)->nullable();
            $table->json('payload')->nullable();
            $table->string('batch_id', 36);
            $table->timestamps();

            $table->unique(['entity', 'legacy_id']);
            $table->index('batch_id');
            $table->index(['target_table', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_import_map');
    }
};
