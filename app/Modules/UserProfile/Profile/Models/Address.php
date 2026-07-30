<?php

namespace App\Modules\UserProfile\Profile\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Address extends Model
{
    protected $primaryKey = 'address_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'address_id',
        'street',
        'number',
        'additional_address',
        'zip_code',
        'city',
        'country',
        'longitude',
        'latitude',
    ];

    protected $casts = [
        'longitude' => 'float',
        'latitude' => 'float',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->address_id)) {
                $model->address_id = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }

    public function contact(): HasOne
    {
        return $this->hasOne(Contact::class, 'address_id', 'address_id');
    }
}
