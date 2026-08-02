<?php

namespace App\Modules\UserProfile\B2B\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A pending invitation for someone to join a B2B company.
 *
 * `token_hash` holds only the SHA-256 of the emailed token — the plaintext is
 * never persisted, so the table is not a source of usable invitation links.
 */
class B2bInvitation extends Model
{
    protected $table = 'b2b_invitations';

    protected $primaryKey = 'invitation_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'invitation_id',
        'b2b_id',
        'email',
        'role',
        'permissions',
        'vehicle_scope',
        'token_hash',
        'invited_by_user_id',
        'expires_at',
        'accepted_at',
        'accepted_by_user_id',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->invitation_id)) {
                $model->invitation_id = (string) Str::uuid();
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(B2B::class, 'b2b_id', 'b2b_id');
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    /** Still open: not accepted, not revoked, not expired. */
    public function isPending(): bool
    {
        return $this->accepted_at === null
            && $this->revoked_at === null
            && $this->expires_at->isFuture();
    }

    public function status(): string
    {
        return match (true) {
            $this->accepted_at !== null => 'accepted',
            $this->revoked_at !== null => 'revoked',
            $this->expires_at->isPast() => 'expired',
            default => 'pending',
        };
    }
}
