<?php

namespace App\Modules\PartnerApi\Services;

use App\Modules\PartnerApi\Data\IssuedPartnerToken;
use App\Modules\PartnerApi\Enums\PartnerAbility;
use App\Modules\PartnerApi\Models\PartnerApiToken;
use App\Modules\PartnerApi\Models\PartnerIntegrationClient;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The only place a partner token is created, matched or invalidated.
 *
 * Tokens are stored as a SHA-256 digest of the plaintext, not a bcrypt hash:
 * lookup happens on every request and must be an indexed equality match, and
 * the input is 256 bits of CSPRNG output rather than a human-chosen password,
 * so there is nothing for a slow hash to defend against. This mirrors how
 * Sanctum stores its own tokens.
 */
class PartnerTokenService
{
    /**
     * Mint a new credential. The returned plaintext is the only copy.
     *
     * @param  list<string>|null  $abilities  null grants every currently-defined ability, written out explicitly.
     */
    public function issue(
        PartnerIntegrationClient $client,
        string $name = 'default',
        ?array $abilities = null,
        ?CarbonInterface $expiresAt = null,
        ?string $issuedBy = null,
    ): IssuedPartnerToken {
        $plainText = $this->generatePlainTextToken($client);

        $token = new PartnerApiToken([
            'name' => $name,
            'abilities' => $abilities ?? PartnerAbility::values(),
            'expires_at' => $expiresAt,
            'issued_by' => $issuedBy,
        ]);

        $token->partner_integration_client_id = $client->id;
        $token->token_hash = $this->hash($plainText);
        $token->save();

        return new IssuedPartnerToken($token, $plainText);
    }

    /**
     * Issue a replacement and retire the current tokens.
     *
     * The previous credentials stay valid for `$graceMinutes` so a partner can
     * roll the secret through their deployment without a synchronised cutover.
     * A grace of 0 revokes them the moment the new one is minted.
     *
     * @param  list<string>|null  $abilities  null reuses the newest existing token's scope set.
     */
    public function rotate(
        PartnerIntegrationClient $client,
        ?array $abilities = null,
        ?CarbonInterface $expiresAt = null,
        int $graceMinutes = 0,
        ?string $issuedBy = null,
        string $name = 'rotated',
    ): IssuedPartnerToken {
        $current = $this->unrevokedTokens($client)->get();

        $issued = $this->issue(
            client: $client,
            name: $name,
            abilities: $abilities ?? $current->first()?->abilityValues(),
            expiresAt: $expiresAt,
            issuedBy: $issuedBy,
        );

        $revokeAt = Carbon::now()->addMinutes(max(0, $graceMinutes));

        foreach ($current as $token) {
            $token->forceFill(['revoked_at' => $revokeAt])->save();
        }

        return $issued;
    }

    /**
     * Revoke every live token for a client, effective now. Returns how many
     * were affected.
     *
     * Deliberately also pulls forward a revocation that a rotation had
     * scheduled: this is the incident-response path, and "already scheduled
     * to die in ten minutes" is not good enough when a credential has leaked.
     */
    public function revokeAll(PartnerIntegrationClient $client): int
    {
        return $this->usableTokens($client)->update(['revoked_at' => Carbon::now()]);
    }

    public function revoke(PartnerApiToken $token): void
    {
        if ($token->isRevoked()) {
            return;
        }

        $token->forceFill(['revoked_at' => Carbon::now()])->save();
    }

    /**
     * Find the token behind a plaintext bearer credential.
     *
     * Returns the row whatever its state — revoked and expired tokens come
     * back too, so the caller can answer with the specific reason rather than
     * a blanket "invalid token", which would leave a partner unable to tell a
     * typo from an expired secret.
     */
    public function findByPlainText(string $plainText): ?PartnerApiToken
    {
        $plainText = trim($plainText);

        if ($plainText === '') {
            return null;
        }

        return PartnerApiToken::query()
            ->with(['client.company', 'client.user'])
            ->where('token_hash', $this->hash($plainText))
            ->first();
    }

    /**
     * Record usage. Written with saveQuietly() and only when the value would
     * actually change, so a busy partner does not generate one write per
     * request just to move a timestamp by milliseconds.
     */
    public function recordUsage(PartnerApiToken $token, ?string $ip = null): void
    {
        $now = Carbon::now();

        if ($token->last_used_at !== null
            && $token->last_used_at->diffInSeconds($now) < 1
            && $token->last_used_ip === $ip) {
            return;
        }

        $token->forceFill([
            'last_used_at' => $now,
            'last_used_ip' => $ip,
        ])->saveQuietly();
    }

    public function hash(string $plainText): string
    {
        return hash('sha256', $plainText);
    }

    /**
     * Tokens that would authenticate right now — including ones with a
     * revocation already scheduled for later.
     *
     * @return Builder<PartnerApiToken>
     */
    private function usableTokens(PartnerIntegrationClient $client): Builder
    {
        return $this->unexpiredTokens($client)
            ->where(function ($query) {
                $query->whereNull('revoked_at')->orWhere('revoked_at', '>', Carbon::now());
            });
    }

    /**
     * Tokens with no revocation at all. Rotation retires these; one already
     * scheduled keeps the earlier deadline it was given rather than having its
     * life quietly extended by a second rotation.
     *
     * @return Builder<PartnerApiToken>
     */
    private function unrevokedTokens(PartnerIntegrationClient $client): Builder
    {
        return $this->unexpiredTokens($client)->whereNull('revoked_at');
    }

    /**
     * @return Builder<PartnerApiToken>
     */
    private function unexpiredTokens(PartnerIntegrationClient $client): Builder
    {
        return PartnerApiToken::query()
            ->where('partner_integration_client_id', $client->id)
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', Carbon::now());
            })
            ->orderByDesc('created_at');
    }

    /**
     * `lbp_live_<64 hex chars>` — prefixed so a leaked credential is
     * recognisable on sight and placeable to an environment without a lookup.
     */
    private function generatePlainTextToken(PartnerIntegrationClient $client): string
    {
        $prefix = (string) config('partner_api.token.prefix', 'lbp');
        $bytes = max(16, (int) config('partner_api.token.bytes', 32));

        return $prefix.'_'.$client->environment->tokenSegment().'_'.bin2hex(random_bytes($bytes));
    }
}
