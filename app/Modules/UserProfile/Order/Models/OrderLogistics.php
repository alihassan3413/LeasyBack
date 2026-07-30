<?php

namespace App\Modules\UserProfile\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderLogistics extends Model
{
    protected $table = 'leasyback_order_logistics';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'auftragsnummer',
        'pickup_profile_id',
        'delivery_profile_id',
        'pickup_details',
        'delivery_details',
        'delivery_same_as_pickup',
        'pickup_notes',
        'delivery_notes',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $casts = [
        'pickup_details' => 'array',
        'delivery_details' => 'array',
        'delivery_same_as_pickup' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->id)) {
                $model->id = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(LeasybackOrder::class, 'auftragsnummer', 'auftragsnummer');
    }

    public function pickupProfile(): BelongsTo
    {
        return $this->belongsTo(LogisticsAddressProfile::class, 'pickup_profile_id', 'id');
    }

    public function deliveryProfile(): BelongsTo
    {
        return $this->belongsTo(LogisticsAddressProfile::class, 'delivery_profile_id', 'id');
    }
}
