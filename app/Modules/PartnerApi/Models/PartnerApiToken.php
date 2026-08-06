<?php

namespace App\Modules\PartnerApi\Models;

use App\Modules\PartnerApi\Enums\PartnerAbility;
use Database\Factories\PartnerApiTokenFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One long-lived bearer credential.
 *
 * The plaintext never reaches this model: callers hand a hash to
 * `PartnerTokenService`, which is the only place a token is created,
 * matched or invalidated. Everything here answers questions about a token
 * that has already been found.
 */
class PartnerApiToken extends Model
{
    /** @use HasFactory<PartnerApiTokenFactory> */
    use HasFactory, HasUuids;

    protected $table = 'partner_api_tokens';

    /**
     * `token_hash` is not fillable: the hash is the credential, and it is set
     * exactly once, by PartnerTokenService::issue().
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'abilities',
        'expires_at',
        'issued_by',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'token_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function newFactory(): PartnerApiTokenFactory
    {
        return PartnerApiTokenFactory::new();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(PartnerIntegrationClient::class, 'partner_integration_client_id', 'id');
    }

    /**
     * Revocation can be scheduled: `partner:token:rotate --grace-minutes` sets
     * `revoked_at` in the future so the partner can deploy the replacement
     * before the old secret dies. A future timestamp therefore means "still
     * valid, but not for long", not "already dead".
     */
    public function isRevoked(): bool
    {
        return $this->revoked_at !== null && ! $this->revoked_at->isFuture();
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    /**
     * Whether this token carries a scope.
     *
     * Accepts either the enum or its raw string so call sites in later phases
     * can gate on `PartnerAbility::ReadVehicles` while route middleware passes
     * the value it was configured with.
     */
    public function can(PartnerAbility|string $ability): bool
    {
        $abilities = $this->abilityValues();

        if (in_array(PartnerAbility::WILDCARD, $abilities, true)) {
            return true;
        }

        $needle = $ability instanceof PartnerAbility ? $ability->value : $ability;

        return in_array($needle, $abilities, true);
    }

    /**
     * The stored scope set, normalised to a list of strings.
     *
     * @return list<string>
     */
    public function abilityValues(): array
    {
        return array_values(array_filter(
            (array) ($this->abilities ?? []),
            fn (mixed $ability) => is_string($ability) && $ability !== '',
        ));
    }

    /**
     * The scope set as it should appear to the partner: the wildcard expanded,
     * so `GET /me` never answers a partner's "what may I call" with `*`.
     *
     * @return list<string>
     */
    public function resolvedAbilityValues(): array
    {
        $abilities = $this->abilityValues();

        if (in_array(PartnerAbility::WILDCARD, $abilities, true)) {
            return PartnerAbility::values();
        }

        return $abilities;
    }
}
