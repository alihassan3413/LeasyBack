<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per Base44 record seen by the importer (see the migration for the
 * status vocabulary). Temporary: dropped after the migration sign-off.
 */
class LegacyImportMap extends Model
{
    protected $table = 'legacy_import_map';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }
}
