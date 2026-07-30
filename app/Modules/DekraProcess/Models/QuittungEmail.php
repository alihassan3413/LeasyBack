<?php

namespace App\Modules\DekraProcess\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuittungEmail extends Model
{
    use HasUuids;

    protected $table = 'quittung_emails';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'partner_id',
        'bezeichnung',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(QuittungPartner::class, 'partner_id', 'id');
    }
}
