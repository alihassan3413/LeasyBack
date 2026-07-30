<?php

namespace App\Modules\DekraProcess\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DekraClient extends Model
{
    protected $table = 'clients';

    protected $primaryKey = 'client_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'client_id',
        'client_name',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function dienstleistungsobjekte(): HasMany
    {
        return $this->hasMany(Dienstleistungsobjekt::class, 'client_id', 'client_id');
    }

    public function kundenAuftraege(): HasMany
    {
        return $this->hasMany(KundenAuftrag::class, 'client_id', 'client_id');
    }

    public function anlagen(): HasMany
    {
        return $this->hasMany(AnlageListe::class, 'client_id', 'client_id');
    }
}
