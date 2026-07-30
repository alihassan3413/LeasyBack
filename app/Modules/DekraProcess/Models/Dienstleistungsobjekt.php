<?php

namespace App\Modules\DekraProcess\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Dienstleistungsobjekt extends Model
{
    protected $table = 'dienstleistungsobjekt';

    protected $primaryKey = 'objekt_id';

    public $timestamps = false;

    protected $fillable = [
        'client_id',
        'objekt_art',
        'amtliches_kennzeichen',
        'erstzulassung',
        'fahrzeugidentifizierungsnummer',
        'hersteller',
        'verkaufsbezeichnung',
        'leasing_nummer',
        'objekt_create_date',
    ];

    protected $casts = [
        'objekt_create_date' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(DekraClient::class, 'client_id', 'client_id');
    }

    public function kundenAuftraege(): HasMany
    {
        return $this->hasMany(KundenAuftrag::class, 'objekt_id', 'objekt_id');
    }
}
