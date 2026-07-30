<?php

namespace App\Modules\DekraProcess\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuittungKundenreferenz extends Model
{
    use HasUuids;

    protected $table = 'quittung_kundenreferenzen';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'quittung_id',
        'typ',
        'nummer',
    ];

    public function quittung(): BelongsTo
    {
        return $this->belongsTo(Quittung::class, 'quittung_id', 'id');
    }
}
