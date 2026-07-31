<?php

namespace App\Modules\UserProfile\Profile\Models;

use App\Models\User;
use Database\Factories\UserPreferenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class UserPreference extends Model
{
    use HasFactory;

    protected static function newFactory(): UserPreferenceFactory
    {
        return UserPreferenceFactory::new();
    }

    protected $table = 'user_preferences';

    protected $primaryKey = 'preference_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'preference_id',
        'user_id',
        'timezone',
        'sprache',
        'benachrichtigungseinstellungen_push',
        'benachrichtigungseinstellungen_email',
    ];

    protected $casts = [
        'benachrichtigungseinstellungen_push' => 'boolean',
        'benachrichtigungseinstellungen_email' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->preference_id)) {
                $model->preference_id = (string) Str::uuid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
