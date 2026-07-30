<?php

namespace App\Modules\UserProfile\Tim\Models;

use Illuminate\Database\Eloquent\Model;

class TimToken extends Model
{
    protected $table = 'tim_token';
    protected $primaryKey = 'id';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'id',
        'client_id',
        'session',
        'username',
        'updated_by_user_id',
        'updated_at',
    ];

    protected $casts = [
        'updated_at' => 'datetime',
    ];

    /**
     * Upsert the singleton TIM token row.
     */
    public static function upsertToken(string $clientId, string $session, string $username, ?int $userId = null): void
    {
        self::updateOrCreate(
            ['id' => 1],
            [
                'client_id' => $clientId,
                'session' => $session,
                'username' => $username,
                'updated_by_user_id' => $userId,
                'updated_at' => now(),
            ]
        );
    }

    /**
     * Get the current TIM token or null.
     */
    public static function current(): ?self
    {
        return self::find(1);
    }
}
