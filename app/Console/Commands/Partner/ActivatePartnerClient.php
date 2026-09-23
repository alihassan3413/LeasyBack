<?php

namespace App\Console\Commands\Partner;

use App\Modules\PartnerApi\Models\PartnerIntegrationClient;

/**
 * Restores a suspended integration. See PartnerClientStateCommand.
 */
class ActivatePartnerClient extends PartnerClientStateCommand
{
    protected $signature = 'partner:activate
                            {slug : The partner slug}
                            {--environment=sandbox : sandbox or production}
                            {--force : Skip the production confirmation prompt}';

    protected $description = 'Activate a partner integration client';

    protected function targetState(): bool
    {
        return true;
    }

    protected function report(PartnerIntegrationClient $client): void
    {
        $this->components->info("{$client->reference()} is now active. Its existing tokens work again.");
    }
}
