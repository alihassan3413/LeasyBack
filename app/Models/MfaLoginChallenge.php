<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One half-finished authentication.
 *
 * Deliberately thin: the row is state, and every decision about it — whether
 * it is still usable, what a wrong code costs — belongs to MfaChallengeService
 * so that both login paths reach the same verdict.
 */
class MfaLoginChallenge extends Model
{
    public const PURPOSE_VERIFY = 'verify';

    public const PURPOSE_ENROLL = 'enroll';

    protected $fillable = [
        'user_id',
        'ticket_hash',
        'purpose',
        'code_hash',
        'attempts',
        'sends',
        'last_sent_at',
        'expires_at',
    ];

    /**
     * `ticket_hash` and `code_hash` are never sent anywhere, but hiding them
     * means an accidental toArray() in a log or a response cannot leak them.
     */
    protected $hidden = ['ticket_hash', 'code_hash'];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'sends' => 'integer',
            'last_sent_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
