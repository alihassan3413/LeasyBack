<?php

namespace App\Modules\UserProfile\Profile\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhoneNumber extends Model
{
    protected $primaryKey = 'phone_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'phone_id',
        'contact_id',
        'international_prefix',
        'phone_number',
        'is_primary_contact',
    ];

    protected $casts = [
        'is_primary_contact' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->phone_id)) {
                $model->phone_id = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id', 'contact_id');
    }
}
