<?php

namespace App\Modules\DekraProcess\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnlageListe extends Model
{
    protected $table = 'anlage_liste';

    protected $primaryKey = 'anlage_id';

    public $timestamps = false;

    protected $fillable = [
        'beauftragungsnummer',
        'client_id',
        'beschreibung',
        'inhalt',
        'mime_type',
        'feile_name',
        'feile_typ',
        'anlage_created_date',
    ];

    protected $casts = [
        'anlage_created_date' => 'datetime',
    ];

    public function kundenAuftrag(): BelongsTo
    {
        return $this->belongsTo(KundenAuftrag::class, 'beauftragungsnummer', 'beauftragungsnummer');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(DekraClient::class, 'client_id', 'client_id');
    }
}
