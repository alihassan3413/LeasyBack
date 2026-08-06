<?php

namespace App\Modules\PartnerApi\Services;

use App\Models\User;
use App\Modules\PartnerApi\Enums\PartnerAbility;
use App\Modules\PartnerApi\Enums\PartnerEnvironment;
use App\Modules\PartnerApi\Models\PartnerApiToken;
use App\Modules\PartnerApi\Models\PartnerIntegrationClient;
use App\Modules\UserProfile\B2B\Models\B2B;
use RuntimeException;

/**
 * Who the current partner request is: which integration client, acting as
 * which user, on behalf of which company, in which environment, holding
 * which abilities.
 *
 * The Partner API's counterpart to B2bContext, and registered the same way —
 * a scoped singleton, established once by AuthenticatePartner and read by
 * everything downstream. That indirection is the point: no controller,
 * service or query in this API ever derives the company from request input,
 * because the only available answer comes from the token.
 *
 * Accessors throw rather than returning null when nothing has been
 * established. Reaching one outside the authenticated route group is a
 * programming error, and the failure mode of returning null there would be a
 * query silently running unscoped.
 */
class PartnerContext
{
    private ?PartnerApiToken $token = null;

    private ?PartnerIntegrationClient $client = null;

    private ?User $user = null;

    private ?B2B $company = null;

    private ?string $requestId = null;

    /**
     * Bind a verified token to this request.
     *
     * The token must already have been checked for revocation, expiry and
     * client/company/user state — this method establishes identity, it does
     * not authorise. AuthenticatePartner is the only intended caller.
     */
    public function establish(PartnerApiToken $token, PartnerIntegrationClient $client, User $user, B2B $company): void
    {
        $this->token = $token;
        $this->client = $client;
        $this->user = $user;
        $this->company = $company;
    }

    public function isEstablished(): bool
    {
        return $this->token !== null;
    }

    public function token(): PartnerApiToken
    {
        return $this->token ?? throw $this->missing('token');
    }

    public function client(): PartnerIntegrationClient
    {
        return $this->client ?? throw $this->missing('integration client');
    }

    /** The dedicated integration user every partner action is attributed to. */
    public function user(): User
    {
        return $this->user ?? throw $this->missing('integration user');
    }

    public function company(): B2B
    {
        return $this->company ?? throw $this->missing('company');
    }

    /**
     * The one company id every query in this API must be scoped to.
     */
    public function companyId(): string
    {
        return $this->company()->b2b_id;
    }

    public function environment(): PartnerEnvironment
    {
        return $this->client()->environment;
    }

    /**
     * The token's scope set, wildcard expanded.
     *
     * @return list<string>
     */
    public function abilities(): array
    {
        return $this->token()->resolvedAbilityValues();
    }

    public function can(PartnerAbility|string $ability): bool
    {
        return $this->isEstablished() && $this->token()->can($ability);
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    public function setRequestId(string $requestId): void
    {
        $this->requestId = $requestId;
    }

    /**
     * Identity as the partner sees it. Deliberately excludes the token hash,
     * the internal user id and anything about other companies.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'client' => [
                'slug' => $this->client()->slug,
                'name' => $this->client()->name,
                'environment' => $this->environment()->value,
            ],
            'company' => [
                'id' => $this->companyId(),
                'name' => $this->company()->company_name,
            ],
            'token' => [
                'name' => $this->token()->name,
                'abilities' => $this->abilities(),
                'expires_at' => $this->token()->expires_at?->toIso8601String(),
                'last_used_at' => $this->token()->last_used_at?->toIso8601String(),
            ],
        ];
    }

    private function missing(string $what): RuntimeException
    {
        return new RuntimeException(
            "No partner {$what} on this request. PartnerContext is only available inside the "
            .'authenticated Partner API route group.'
        );
    }
}
