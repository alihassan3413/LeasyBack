<?php

namespace App\Modules\UserProfile\Order\Models;

use App\Models\User;
use App\Modules\UserProfile\Offer\Models\LeasybackOffer;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LeasybackOrder extends Model
{
    protected $table = 'leasyback_orders';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id',
        'vehicle_id',
        'auftragsnummer',
        'leasyback_partner',
        'order_status',
        'request_payload',
        'response_status',
        'response_body',
        'created_by_user_id',
        'sent_at',
        'created_at',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_body' => 'json',
        'response_status' => 'integer',
        'sent_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->id)) {
                $model->id = (string) \Illuminate\Support\Str::uuid();
            }
            if (empty($model->created_at)) {
                $model->created_at = now();
            }
        });
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id', 'vehicle_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id', 'id');
    }

    public function confirmation(): HasOne
    {
        return $this->hasOne(OrderConfirmation::class, 'auftragsnummer', 'auftragsnummer');
    }

    public function statusUpdates(): HasMany
    {
        return $this->hasMany(OrderStatusUpdate::class, 'auftragsnummer', 'auftragsnummer');
    }

    public function offers(): HasMany
    {
        return $this->hasMany(LeasybackOffer::class, 'order_id', 'id');
    }
}
