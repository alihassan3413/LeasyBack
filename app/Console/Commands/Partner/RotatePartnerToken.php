<?php

namespace App\Console\Commands\Partner;

use App\Modules\PartnerApi\Enums\PartnerAbility;
use App\Modules\PartnerApi\Services\PartnerTokenService;
use Illuminate\Support\Carbon;

/**
 * Issues a replacement credential and retires the current ones.
 *
 * `--grace-minutes` exists because a hard cutover is the reason rotations get
 * postponed: with a grace window the partner deploys the new secret at their
 * own pace and the old one dies on schedule. A compromised credential is
 * rotated with grace 0, which is the default.
 */
class RotatePartnerToken extends PartnerCommand
{
    protected $signature = 'partner:token:rotate
                            {slug : The partner slug}
                            {--environment=sandbox : sandbox or production}
                            {--abilities= : Comma-separated scopes (defaults to the current token\'s)}
                            {--expires-in-days= : Optional lifetime for the new token}
                            {--grace-minutes= : Keep the previous tokens valid this long (default from config)}
                            {--issued-by= : Who is issuing this credential, recorded for audit}
                            {--force : Skip the production confirmation prompt}';

    protected $description = 'Issue a replacement API token for a partner and revoke the previous ones';

    public function handle(PartnerTokenService $tokens): int
    {
        $environment = $this->resolveEnvironment();

        if ($environment === null) {
            return self::FAILURE;
        }

        if (! $this->confirmSensitiveOperation('Rotating a live partner credential', $environment)) {
            return self::FAILURE;
        }

        $client = $this->resolveClient((string) $this->argument('slug'), $environment);

        if ($client === null) {
            return self::FAILURE;
        }

        $abilities = $this->parseAbilities();
        $unknown = array_diff($abilities ?? [], PartnerAbility::values());

        if ($unknown !== []) {
            $this->components->error('Unknown abilities: '.implode(', ', $unknown));

            return self::FAILURE;
        }

        $expiresInDays = $this->option('expires-in-days') ?? config('partner_api.token.default_expiry_days');
        $graceMinutes = $this->option('grace-minutes')
            ?? config('partner_api.token.rotation_grace_minutes');

        $issued = $tokens->rotate(
            client: $client,
            abilities: $abilities,
            expiresAt: $expiresInDays === null ? null : Carbon::now()->addDays((int) $expiresInDays),
            graceMinutes: (int) $graceMinutes,
            issuedBy: $this->option('issued-by') ?: 'cli',
        );

        $this->components->info("Rotated the credential for {$client->reference()}.");
        $this->components->twoColumnDetail(
            'Previous tokens revoked',
            (int) $graceMinutes === 0 ? 'immediately' : "in {$graceMinutes} minute(s)",
        );
        $this->displayToken($issued, $client);

        return self::SUCCESS;
    }
}
