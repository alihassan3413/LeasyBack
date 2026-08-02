<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pending invitations to join a B2B company.
 *
 * Only the SHA-256 hash of the invitation token is stored, the same way
 * Laravel stores password-reset tokens: the plaintext exists once, in the
 * emailed link. A leaked database row therefore cannot be turned back into a
 * usable invitation link.
 *
 * The role/permissions/vehicle_scope the owner chose are captured on the
 * invitation itself, so accepting it produces exactly the membership that was
 * offered — the accepting user never supplies any part of their own access.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('b2b_invitations', function (Blueprint $table) {
            $table->uuid('invitation_id')->primary();
            $table->foreignUuid('b2b_id')->constrained('b2b', 'b2b_id')->cascadeOnDelete();
            $table->string('email');
            $table->string('role', 20)->default('member');
            $table->json('permissions')->nullable();
            $table->string('vehicle_scope', 10)->default('all');
            $table->string('token_hash', 64)->unique();
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->foreignId('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->index(['b2b_id', 'email']);
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('b2b_invitations');
    }
};
