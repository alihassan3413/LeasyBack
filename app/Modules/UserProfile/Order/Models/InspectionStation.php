<?php

namespace App\Modules\UserProfile\Order\Models;

use Illuminate\Database\Eloquent\Model;

class InspectionStation extends Model
{
    protected $primaryKey = 'station_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'station_id',
        'provider',
        'name',
        'strasse',
        'plz',
        'ort',
        'bundesland',
        'land',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->station_id)) {
                $model->station_id = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }
}
