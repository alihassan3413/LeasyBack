<?php

namespace App\Modules\DekraProcess\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KundenAuftrag extends Model
{
    protected $table = 'kunden_auftrag';

    protected $primaryKey = 'auftrag_id';

    public $timestamps = false;

    protected $fillable = [
        'beauftragungsnummer',
        'client_id',
        'objekt_id',
        'orte_id',
        'auftrag_created_date',
        'bestellung_bestaetigen',
        'auftrag_bemerkung',
    ];

    protected $casts = [
        'bestellung_bestaetigen' => 'boolean',
        'auftrag_created_date' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(DekraClient::class, 'client_id', 'client_id');
    }

    public function dienstleistungsobjekt(): BelongsTo
    {
        return $this->belongsTo(Dienstleistungsobjekt::class, 'objekt_id', 'objekt_id');
    }

    public function besichtigungOrte(): BelongsTo
    {
        return $this->belongsTo(BesichtigungOrte::class, 'orte_id', 'orte_id');
    }

    public function anlagen(): HasMany
    {
        return $this->hasMany(AnlageListe::class, 'beauftragungsnummer', 'beauftragungsnummer');
    }
}
