<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `user_workshops` (many-to-many workshop membership) has had zero code
 * references since it was created — no model, no controller, nothing.
 * The `workshops` table is already 1:1 with `user_id` (Workshop::user() is
 * a BelongsTo, not a pivot-backed relation), a deliberate simplification
 * over the Rust reference schema's many-to-many that Checkpoint 9's audit
 * confirmed as already made. Building multi-staff workshop membership on
 * top of that now would be new, unrequested feature work contradicting the
 * already-shipped design, not "wiring up dead schema" — so this drops it,
 * per docs/B2C_ADMIN_IMPLEMENTATION_PLAN.md's explicit "wire up or drop"
 * instruction (Checkpoint 12).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('user_workshops');
    }

    public function down(): void
    {
        Schema::create('user_workshops', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workshop_id')->constrained('workshops', 'id')->cascadeOnDelete();
            $table->string('role', 50)->default('owner');
            $table->timestampsTz();
            $table->primary(['user_id', 'workshop_id']);
        });
    }
};
