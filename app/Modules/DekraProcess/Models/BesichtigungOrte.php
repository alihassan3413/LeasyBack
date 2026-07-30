<?php

namespace App\Modules\DekraProcess\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BesichtigungOrte extends Model
{
    protected $table = 'besichtigung_orte';

    protected $primaryKey = 'orte_id';

    public $timestamps = false;

    protected $fillable = [
        'orte_name',
        'name4',
        'strasse',
        'plz',
        'ort',
        'rolle',
        'is_valid',
        'orte_create_date',
    ];

    protected $casts = [
        'is_valid' => 'boolean',
        'orte_create_date' => 'datetime',
    ];

    public function kundenAuftraege(): HasMany
    {
        return $this->hasMany(KundenAuftrag::class, 'orte_id', 'orte_id');
    }
}
