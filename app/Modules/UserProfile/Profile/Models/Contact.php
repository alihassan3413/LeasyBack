<?php

namespace App\Modules\UserProfile\Profile\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    protected $primaryKey = 'contact_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'contact_id',
        'salutation',
        'first_name',
        'last_name',
        'address_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->contact_id)) {
                $model->contact_id = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'address_id', 'address_id');
    }

    public function phoneNumbers(): HasMany
    {
        return $this->hasMany(PhoneNumber::class, 'contact_id', 'contact_id');
    }
}
