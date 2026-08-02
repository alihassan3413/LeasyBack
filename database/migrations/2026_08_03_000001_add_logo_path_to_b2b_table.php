<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `logo_path` is the disk-relative path of an uploaded company logo on the
 * public disk — the source of truth, mirroring what `workshops.logo_path`
 * already does (see 2026_08_01_000002_add_logo_path_to_workshops_table).
 * The existing `logo_url` column stays as the derived, readable value used
 * by the admin area and the API's B2B profile response; keeping the path
 * alongside it is what makes replacing/removing a logo able to delete the
 * previous file instead of orphaning it on disk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('b2b', function (Blueprint $table) {
            $table->string('logo_path')->nullable()->after('logo_url');
        });
    }

    public function down(): void
    {
        Schema::table('b2b', function (Blueprint $table) {
            $table->dropColumn('logo_path');
        });
    }
};
