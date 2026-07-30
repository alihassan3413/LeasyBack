<?php

namespace App\Modules\DekraProcess\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuittungStatus extends Model
{
    use HasUuids;

    protected $table = 'quittung_status';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'quittung_id',
        'bezeichnung',
        'zusatzinformation',
    ];

    protected $casts = [
        'zusatzinformation' => 'datetime',
    ];

    public function quittung(): BelongsTo
    {
        return $this->belongsTo(Quittung::class, 'quittung_id', 'id');
    }
}
