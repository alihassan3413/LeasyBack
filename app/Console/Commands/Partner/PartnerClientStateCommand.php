<?php

namespace App\Console\Commands\Partner;

use App\Modules\PartnerApi\Models\PartnerIntegrationClient;

/**
 * Turns an integration off and on again without touching its credentials.
 *
 * Distinct from revocation on purpose: deactivating suspends a partner
 * reversibly — a billing dispute, a migration window, a suspected but
 * unconfirmed leak — and reactivating restores the *same* token, so nothing
 * has to be re-shared. Revocation is the irreversible one.
 *
 * The two commands differ only in the boolean they write, so the lookup, the
 * production confirmation and the no-op case live here once.
 */
abstract class PartnerClientStateCommand extends PartnerCommand
{
    /** True activates the client, false deactivates it. */
    abstract protected function targetState(): bool;

    abstract protected function report(PartnerIntegrationClient $client): void;

    public function handle(): int
    {
        $activate = $this->targetState();
        $verb = $activate ? 'Activating' : 'Deactivating';

        $environment = $this->resolveEnvironment();

        if ($environment === null) {
            return self::FAILURE;
        }

        if (! $this->confirmSensitiveOperation("{$verb} a live partner integration", $environment)) {
            return self::FAILURE;
        }

        $client = $this->resolveClient((string) $this->argument('slug'), $environment);

        if ($client === null) {
            return self::FAILURE;
        }

        if ($client->is_active === $activate) {
            $state = $activate ? 'active' : 'deactivated';
            $this->components->info("{$client->reference()} is already {$state} — nothing to do.");

            return self::SUCCESS;
        }

        $client->forceFill(['is_active' => $activate])->save();

        $this->report($client);

        return self::SUCCESS;
    }
}
