<?php

namespace App\Modules\UserProfile\Order\Models;

use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One vehicle of a multi-vehicle order (see the 2026_10_03 migration).
 *
 * `active_vehicle_id` is derived, never supplied: it is set to the vehicle
 * while the order is open and cleared by LeasybackOrder when the order
 * closes — the same rule leasyback_orders.active_vehicle_id follows.
 */
class OrderVehicle extends Model
{
    protected $table = 'leasyback_order_vehicles';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['id', 'order_id', 'vehicle_id', 'position', 'created_at'];

    protected $casts = [
        'position' => 'integer',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }

            if (empty($model->created_at)) {
                $model->created_at = now();
            }

            // A new row belongs to an open order; LeasybackOrder clears it on close.
            $model->active_vehicle_id = $model->vehicle_id;
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(LeasybackOrder::class, 'order_id', 'id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id', 'vehicle_id');
    }
}