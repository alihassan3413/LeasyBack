<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('b2b_appraisal_positions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->text('auftragsnummer')->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('component');
            $table->text('damage_description')->nullable();
            $table->decimal('original_amount_net', 10, 2);
            $table->decimal('chargeable_amount_net', 10, 2)->nullable();
            $table->text('repair_method')->nullable();
            $table->string('source', 16)->default('manual');
            $table->json('damage_image_document_ids')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->foreign('order_id')->references('id')->on('leasyback_orders')->cascadeOnDelete();
            $table->index(['order_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('b2b_appraisal_positions');
    }
};
