<?php

namespace App\Modules\UserProfile\Tim\Models;

use Illuminate\Database\Eloquent\Model;

class TimBewertung extends Model
{
    protected $table = 'tim_bewertung';
    protected $primaryKey = 'bewertung_id';
    public $incrementing = false;
    protected $keyType = 'integer';

    protected $fillable = [
        'bewertung_id',
        'uid',
        'gutachten_nummer',
        'auftragsnummer',
        'fin',
        'hersteller',
        'modell',
        'farbe',
        'gutachtendatum',
        'kilometerstand',
        'waehrung',
        'kunde',
        'produkt',
        's3_bucket',
        's3_key',
        'updated_by_user_id',
    ];

    protected $casts = [
        'bewertung_id' => 'integer',
        'gutachtendatum' => 'date',
        'kilometerstand' => 'integer',
    ];
}
