<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `logo_path` is the disk-relative path of an uploaded logo file on the
 * public disk (see WorkshopController::uploadLogo/deleteLogo) — the source
 * of truth. The existing `logo_url` column is left in place but is no
 * longer directly client-settable (see WorkshopController::updateRules());
 * the public URL is derived from `logo_path` on demand rather than trusted
 * as an arbitrary client-supplied string.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workshops', function (Blueprint $table) {
            $table->string('logo_path')->nullable()->after('logo_url');
        });
    }

    public function down(): void
    {
        Schema::table('workshops', function (Blueprint $table) {
            $table->dropColumn('logo_path');
        });
    }
};
