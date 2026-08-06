<?php

namespace App\Console\Commands\Partner;

use App\Modules\PartnerApi\Models\PartnerIntegrationClient;

/**
 * Suspends an integration reversibly. See PartnerClientStateCommand.
 */
class DeactivatePartnerClient extends PartnerClientStateCommand
{
    protected $signature = 'partner:deactivate
                            {slug : The partner slug}
                            {--environment=sandbox : sandbox or production}
                            {--force : Skip the production confirmation prompt}';

    protected $description = 'Deactivate a partner integration client without revoking its tokens';

    protected function targetState(): bool
    {
        return false;
    }

    protected function report(PartnerIntegrationClient $client): void
    {
        $this->components->info("{$client->reference()} is now deactivated. Its tokens are refused with 403 client_inactive.");
        $this->components->warn('The tokens still exist — use partner:token:revoke if they must be invalidated.');
    }
}
