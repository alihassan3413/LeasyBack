<?php

namespace App\Modules\UserProfile\Profile\Models;

use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Contact extends Model
{
    use HasFactory;

    protected static function newFactory(): ContactFactory
    {
        return ContactFactory::new();
    }

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
                $model->contact_id = (string) Str::uuid();
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
