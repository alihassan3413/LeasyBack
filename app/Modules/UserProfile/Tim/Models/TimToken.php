<?php

namespace App\Modules\UserProfile\Tim\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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
     *
     * A bare `updateOrCreate()` raced: two concurrent logins could both see
     * no row and both attempt an insert, colliding on the id=1 primary key.
     * A migration seeds that row once (see
     * 2026_08_02_000002_seed_tim_token_singleton_row.php), so in normal
     * operation this only ever needs to lock-and-update it; `create()` is
     * kept as a defensive fallback for an environment where the seed
     * somehow never ran, not the expected path.
     */
    public static function upsertToken(string $clientId, string $session, string $username, ?int $userId = null): void
    {
        DB::transaction(function () use ($clientId, $session, $username, $userId) {
            $attributes = [
                'client_id' => $clientId,
                'session' => $session,
                'username' => $username,
                'updated_by_user_id' => $userId,
                'updated_at' => now(),
            ];

            $token = self::query()->lockForUpdate()->find(1);

            if ($token) {
                $token->update($attributes);
            } else {
                self::create(['id' => 1, ...$attributes]);
            }
        });
    }

    /**
     * Get the current TIM token or null.
     */
    public static function current(): ?self
    {
        return self::find(1);
    }
}
