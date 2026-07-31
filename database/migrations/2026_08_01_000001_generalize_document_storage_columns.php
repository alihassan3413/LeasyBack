<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moves document storage off S3-specific column naming now that access goes
 * through the disk-agnostic `documents` filesystem disk (see
 * config/filesystems.php) instead of a hardcoded `Storage::disk('s3')` call.
 *
 * `s3_key` -> `path`: same value (a path relative to whatever disk is
 * configured), renamed so it no longer implies S3 specifically.
 *
 * `s3_bucket`/`s3_url` on vehicle_report_documents become nullable rather
 * than dropped: a bucket is a disk-level config concern now, not a
 * per-row one, and a stored absolute URL doesn't survive a disk swap (URLs
 * are generated on demand via Storage::disk('documents')->url()/
 * temporaryUrl() instead). Nullable rather than dropped since this is a
 * reversible, additive change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_documents', function (Blueprint $table) {
            $table->renameColumn('s3_key', 'path');
        });

        Schema::table('vehicle_report_documents', function (Blueprint $table) {
            $table->renameColumn('s3_key', 'path');
        });

        Schema::table('vehicle_report_documents', function (Blueprint $table) {
            $table->text('s3_bucket')->nullable()->change();
            $table->text('s3_url')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_report_documents', function (Blueprint $table) {
            $table->text('s3_bucket')->nullable(false)->change();
            $table->text('s3_url')->nullable(false)->change();
        });

        Schema::table('vehicle_report_documents', function (Blueprint $table) {
            $table->renameColumn('path', 's3_key');
        });

        Schema::table('vehicle_documents', function (Blueprint $table) {
            $table->renameColumn('path', 's3_key');
        });
    }
};
