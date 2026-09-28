<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Damage a workshop found on the vehicle that the Gutachten does not list.
 *
 * Deliberately not a b2b_appraisal_positions row: those are the appraisal, and
 * writing one here would put unreviewed workshop findings straight into the
 * customer's offer totals and into every other workshop's copy of the job. It
 * is not a quotation item either, because an item prices an appraisal position
 * that exists and its appraisal_position_id is the key the admin comparison
 * groups by.
 *
 * The order is reached through the quotation, so it is not repeated here.
 *
 * No b2b_ prefix: the quotation flow is channel-blind and serves B2C orders
 * through the same service, table and public token as B2B ones. The prefix on
 * the older tables is legacy naming, not a channel boundary, and repeating it
 * on a new shared table would only mislead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workshop_additional_positions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('quotation_id');
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('component');
            // Not nullable, unlike the appraisal's own column: validation
            // makes this required, so the database says the same thing.
            $table->text('damage_description');
            $table->text('repair_method')->nullable();
            $table->decimal('amount_net', 10, 2);
            $table->json('damage_image_document_ids')->nullable();
            $table->timestampsTz();

            $table->foreign('quotation_id')->references('id')->on('b2b_workshop_quotations')->cascadeOnDelete();
            $table->index(['quotation_id', 'sort_order'], 'workshop_additional_quotation_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workshop_additional_positions');
    }
};
