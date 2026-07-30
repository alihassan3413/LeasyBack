<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tim_token', function (Blueprint $table) {
            $table->unsignedSmallInteger('id')->primary();
            $table->text('client_id');
            $table->text('session');
            $table->text('username');
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('updated_at')->useCurrent();
        });

        Schema::create('tim_bewertung', function (Blueprint $table) {
            $table->unsignedBigInteger('bewertung_id')->primary();
            $table->text('uid')->nullable()->index();
            $table->text('gutachten_nummer')->nullable();
            $table->text('auftragsnummer')->nullable()->index();
            $table->text('fin')->nullable();
            $table->text('hersteller')->nullable();
            $table->text('modell')->nullable();
            $table->text('farbe')->nullable();
            $table->date('gutachtendatum')->nullable();
            $table->unsignedBigInteger('kilometerstand')->nullable();
            $table->text('waehrung')->nullable();
            $table->text('kunde')->nullable();
            $table->text('produkt')->nullable();
            $table->text('s3_bucket');
            $table->text('s3_key');
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        Schema::create('vehicle_assessments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->text('uid')->unique();
            $table->text('gutachtennummer')->nullable();
            $table->text('auftragsnummer')->nullable()->index();
            $table->text('fin')->nullable();
            $table->date('gutachtendatum')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('assessment_documents', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('assessment_id');
            $table->text('doc_type');
            $table->unsignedBigInteger('external_id')->nullable();
            $table->text('title')->nullable();
            $table->text('mime')->nullable();
            $table->text('file_format')->nullable();
            $table->integer('sort_order')->nullable();
            $table->text('source_url')->nullable();
            $table->string('source_sha1', 64)->nullable();
            $table->text('showroom_url')->nullable();
            $table->text('caption')->nullable();
            $table->text('image_kind')->nullable();
            $table->text('s3_bucket');
            $table->text('s3_key');
            $table->text('s3_url');
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign('assessment_id')->references('id')->on('vehicle_assessments')->cascadeOnDelete();
            $table->unique(['assessment_id', 'doc_type', 'external_id'], 'assessment_documents_identity_unique');
        });

        Schema::create('vehicle_report_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('auftragsnummer')->index();
            $table->uuid('vehicle_id')->index();
            $table->text('document_type')->nullable();
            $table->text('document_title')->nullable();
            $table->text('s3_bucket');
            $table->text('s3_key');
            $table->text('s3_url');
            $table->boolean('published')->default(false)->index();
            $table->unsignedBigInteger('source_assessment_document_id')->nullable()->unique();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->foreign('vehicle_id')->references('vehicle_id')->on('vehicles')->cascadeOnDelete();
            $table->foreign('source_assessment_document_id')->references('id')->on('assessment_documents')->nullOnDelete();
            $table->unique(['vehicle_id', 'auftragsnummer', 's3_key'], 'vehicle_report_documents_storage_unique');
            $table->index(['auftragsnummer', 'vehicle_id'], 'vehicle_report_documents_order_vehicle_index');
        });

        Schema::create('vehicle_report_document_logs', function (Blueprint $table) {
            $table->uuid('log_id')->primary();
            $table->uuid('document_id')->nullable()->index();
            $table->text('auftragsnummer')->nullable()->index();
            $table->uuid('vehicle_id')->nullable()->index();
            $table->string('action', 32)->index();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->text('s3_bucket')->nullable();
            $table->text('s3_key')->nullable();
            $table->text('s3_url')->nullable();
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_report_document_logs');
        Schema::dropIfExists('vehicle_report_documents');
        Schema::dropIfExists('assessment_documents');
        Schema::dropIfExists('vehicle_assessments');
        Schema::dropIfExists('tim_bewertung');
        Schema::dropIfExists('tim_token');
    }
};