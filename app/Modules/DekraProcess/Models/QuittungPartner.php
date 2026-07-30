<?php

namespace App\Modules\DekraProcess\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuittungPartner extends Model
{
    use HasUuids;

    protected $table = 'quittung_partner';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'quittung_id',
        'name',
        'name2',
        'name4',
        'strasse',
        'plz',
        'ort',
        'land',
        'nummer',
        'telefonnummer',
        'faxnummer',
    ];

    public function quittung(): BelongsTo
    {
        return $this->belongsTo(Quittung::class, 'quittung_id', 'id');
    }

    public function emails(): HasMany
    {
        return $this->hasMany(QuittungEmail::class, 'partner_id', 'id');
    }

    public function rollen(): HasMany
    {
        return $this->hasMany(QuittungPartnerRolle::class, 'partner_id', 'id');
    }
}
