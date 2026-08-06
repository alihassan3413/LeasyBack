<?php

namespace App\Console\Commands\Partner;

use App\Modules\PartnerApi\Services\PartnerTokenService;

/**
 * Kills a partner's credentials immediately.
 *
 * The incident-response command: no grace window, no replacement issued. The
 * client row survives, so `partner:token:rotate` restores access without
 * re-provisioning the integration user or its membership.
 */
class RevokePartnerToken extends PartnerCommand
{
    protected $signature = 'partner:token:revoke
                            {slug : The partner slug}
                            {--environment=sandbox : sandbox or production}
                            {--force : Skip the production confirmation prompt}';

    protected $description = 'Revoke every live API token for a partner, effective immediately';

    public function handle(PartnerTokenService $tokens): int
    {
        $environment = $this->resolveEnvironment();

        if ($environment === null) {
            return self::FAILURE;
        }

        if (! $this->confirmSensitiveOperation('Revoking a live partner credential', $environment)) {
            return self::FAILURE;
        }

        $client = $this->resolveClient((string) $this->argument('slug'), $environment);

        if ($client === null) {
            return self::FAILURE;
        }

        $revoked = $tokens->revokeAll($client);

        if ($revoked === 0) {
            $this->components->info("{$client->reference()} had no live tokens — nothing to revoke.");

            return self::SUCCESS;
        }

        $this->components->info("Revoked {$revoked} token(s) for {$client->reference()}. They stop working now.");
        $this->components->warn('The partner is locked out until partner:token:rotate issues a replacement.');

        return self::SUCCESS;
    }
}
