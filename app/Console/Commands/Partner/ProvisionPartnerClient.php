<?php

namespace App\Console\Commands\Partner;

use App\Modules\PartnerApi\Enums\PartnerAbility;
use App\Modules\PartnerApi\Services\PartnerClientProvisioner;
use App\Modules\UserProfile\B2B\Models\B2B;
use Throwable;

/**
 * Onboards a partner integration for one environment.
 *
 * Everything a partner needs exists after one run: a dedicated integration
 * user, its company membership, the client row, and the first token. Nothing
 * about this is partner-specific — a new integration is a run of this command,
 * not a deployment.
 */
class ProvisionPartnerClient extends PartnerCommand
{
    protected $signature = 'partner:provision
                            {slug : Short identifier for the partner, e.g. "shiftmove"}
                            {--name= : Display name (defaults to the slug)}
                            {--company= : b2b_id of the company this integration acts for}
                            {--environment=sandbox : sandbox or production}
                            {--user-email= : Email for the dedicated integration account (generated if omitted)}
                            {--contact-email= : Technical contact at the partner}
                            {--abilities= : Comma-separated scopes (defaults to all)}
                            {--expires-in-days= : Optional token lifetime; omit for a non-expiring token}
                            {--issued-by= : Who is issuing this credential, recorded for audit}
                            {--force : Skip the production confirmation prompt}';

    protected $description = 'Provision a partner integration client and issue its first API token';

    public function handle(PartnerClientProvisioner $provisioner): int
    {
        $environment = $this->resolveEnvironment();

        if ($environment === null) {
            return self::FAILURE;
        }

        if (! $this->confirmSensitiveOperation('Provisioning a live partner credential', $environment)) {
            return self::FAILURE;
        }

        $companyId = (string) $this->option('company');

        if ($companyId === '') {
            $this->components->error('--company is required: the b2b_id of the company this integration acts for.');

            return self::FAILURE;
        }

        $company = B2B::find($companyId);

        if ($company === null) {
            $this->components->error("No B2B company with b2b_id '{$companyId}'.");

            return self::FAILURE;
        }

        if (! $company->is_active) {
            $this->components->error("Company '{$company->company_name}' is deactivated — its tokens would be refused.");

            return self::FAILURE;
        }

        $abilities = $this->parseAbilities();
        $unknown = array_diff($abilities ?? [], PartnerAbility::values());

        if ($unknown !== []) {
            $this->components->error('Unknown abilities: '.implode(', ', $unknown));
            $this->line('  Available: '.implode(', ', PartnerAbility::values()));

            return self::FAILURE;
        }

        $slug = (string) $this->argument('slug');

        try {
            $issued = $provisioner->provision(
                slug: $slug,
                name: (string) ($this->option('name') ?: $slug),
                environment: $environment,
                company: $company,
                integrationUserEmail: $this->option('user-email') ?: null,
                abilities: $abilities,
                expiresAt: $this->resolveTokenExpiry(),
                contactEmail: $this->option('contact-email') ?: null,
                issuedBy: $this->option('issued-by') ?: 'cli',
            );
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $client = $issued->token->client;

        $this->components->info("Provisioned {$client->reference()} for {$company->company_name}.");
        $this->components->twoColumnDetail('Integration user', (string) $client->user?->email);
        $this->displayToken($issued, $client);

        return self::SUCCESS;
    }
}
