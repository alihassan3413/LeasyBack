<?php

namespace App\Modules\DekraProcess\Models;

use Illuminate\Database\Eloquent\Model;

class AuftragPartner extends Model
{
    protected $table = 'auftrag_partner';

    protected $primaryKey = 'partner_id';

    public $timestamps = false;

    protected $fillable = [
        'partner_name',
        'partner_nummer',
        'partner_rolle',
        'partner_valid',
        'partner_create_date',
    ];

    protected $casts = [
        'partner_valid' => 'boolean',
        'partner_create_date' => 'datetime',
    ];
}
