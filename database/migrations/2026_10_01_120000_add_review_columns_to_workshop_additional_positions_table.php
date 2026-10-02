<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin review of the damage a workshop reports on its own.
 *
 * Every additional position starts as `pending`. An admin either accepts it —
 * which creates a real appraisal position from it, remembered in
 * `appraisal_position_id` — or rejects it. Existing rows become `pending`, so
 * additional damage reported before this change can still be reviewed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workshop_additional_positions', function (Blueprint $table) {
            $table->string('review_status', 20)->default('pending');
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            // The appraisal position created on accept. No foreign key on
            // purpose: an admin may later delete or rework that position, and
            // the review record must survive that.
            $table->uuid('appraisal_position_id')->nullable();

            $table->index(['quotation_id', 'review_status'], 'wap_quotation_review_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('workshop_additional_positions', function (Blueprint $table) {
            $table->dropIndex('wap_quotation_review_status_index');
            $table->dropColumn(['review_status', 'reviewed_at', 'reviewed_by_user_id', 'appraisal_position_id']);
        });
    }
};
