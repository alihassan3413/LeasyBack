<?php

namespace App\Modules\DekraProcess\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Quittung extends Model
{
    use HasUuids;

    protected $table = 'quittungen';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'versandweg',
        'schema_version',
        'erstellt_am',
        'amtliches_kennzeichen',
        'beauftragungsnummer',
        'sap_auftragsnummer',
        'vorgangsnummer',
    ];

    protected $casts = [
        'erstellt_am' => 'datetime',
    ];

    public function kundenreferenzen(): HasMany
    {
        return $this->hasMany(QuittungKundenreferenz::class, 'quittung_id', 'id');
    }

    public function partner(): HasMany
    {
        return $this->hasMany(QuittungPartner::class, 'quittung_id', 'id');
    }

    public function status(): HasOne
    {
        return $this->hasOne(QuittungStatus::class, 'quittung_id', 'id');
    }
}
