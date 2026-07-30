<?php

namespace App\Modules\UserProfile\Vehicle\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleAuditLog extends Model
{
    protected $table = 'vehicle_audit_log';
    protected $primaryKey = 'log_id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'log_id',
        'vehicle_id',
        'changed_by_user_id',
        'action',
        'old_values',
        'new_values',
        'changed_at',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'changed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->log_id)) {
                $model->log_id = (string) \Illuminate\Support\Str::uuid();
            }
            if (empty($model->changed_at)) {
                $model->changed_at = now();
            }
        });
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id', 'vehicle_id');
    }
}
