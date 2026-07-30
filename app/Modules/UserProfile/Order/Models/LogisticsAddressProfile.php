<?php

namespace App\Modules\UserProfile\Order\Models;

use App\Models\User;
use App\Modules\UserProfile\B2B\Models\B2B;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogisticsAddressProfile extends Model
{
    protected $table = 'logistics_address_profiles';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'owner_type',
        'b2b_id',
        'b2c_user_id',
        'profile_name',
        'details',
        'is_default',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $casts = [
        'details' => 'array',
        'is_default' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->id)) {
                $model->id = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }

    public function b2b(): BelongsTo
    {
        return $this->belongsTo(B2B::class, 'b2b_id', 'b2b_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'b2c_user_id', 'id');
    }
}
