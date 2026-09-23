<?php

namespace App\Modules\PartnerApi\Models;

use App\Models\User;
use App\Modules\PartnerApi\Enums\PartnerEnvironment;
use App\Modules\UserProfile\B2B\Models\B2B;
use Database\Factories\PartnerIntegrationClientFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A partner integration identity: one partner, one environment, one company,
 * one dedicated user.
 *
 * Nothing here is partner-specific. Onboarding a new integration is a
 * `partner:provision` run, not a code change.
 */
class PartnerIntegrationClient extends Model
{
    /** @use HasFactory<PartnerIntegrationClientFactory> */
    use HasFactory, HasUuids;

    protected $table = 'partner_integration_clients';

    /**
     * Ownership columns are deliberately absent from `$fillable`. `b2b_id` and
     * `user_id` are the authorization boundary of the entire API and are only
     * ever set by the provisioning service, never mass-assigned.
     *
     * @var list<string>
     */
    protected $fillable = [
        'slug',
        'name',
        'environment',
        'is_active',
        'contact_email',
        'rate_limit_per_minute',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'environment' => PartnerEnvironment::class,
            'is_active' => 'boolean',
            'rate_limit_per_minute' => 'integer',
            'metadata' => 'array',
        ];
    }

    protected static function newFactory(): PartnerIntegrationClientFactory
    {
        return PartnerIntegrationClientFactory::new();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(B2B::class, 'b2b_id', 'b2b_id');
    }

    /** The dedicated integration user every partner request acts as. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(PartnerApiToken::class, 'partner_integration_client_id', 'id');
    }

    public function externalReferences(): HasMany
    {
        return $this->hasMany(PartnerExternalReference::class, 'partner_integration_client_id', 'id');
    }

    /**
     * The effective per-minute request budget for this client.
     */
    public function rateLimitPerMinute(): int
    {
        return $this->rate_limit_per_minute ?? (int) config('partner_api.rate_limit.per_minute');
    }

    /** Human-readable identity used in CLI output and the /me payload. */
    public function reference(): string
    {
        return $this->slug.' ('.$this->environment->value.')';
    }
}
