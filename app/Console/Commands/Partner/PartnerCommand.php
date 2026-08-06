<?php

namespace App\Console\Commands\Partner;

use App\Modules\PartnerApi\Data\IssuedPartnerToken;
use App\Modules\PartnerApi\Enums\PartnerEnvironment;
use App\Modules\PartnerApi\Models\PartnerIntegrationClient;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;

/**
 * Shared plumbing for the partner credential commands.
 *
 * Every one of these is a privileged operation — issuing, retiring or
 * re-enabling a live third-party credential — so they all inherit the same
 * three protections: an explicit `--force` requirement in production
 * (ConfirmableTrait), a locate-or-fail client lookup that never guesses which
 * environment was meant, and a single token-display routine that states
 * plainly that the value is unrecoverable.
 */
abstract class PartnerCommand extends Command
{
    use ConfirmableTrait;

    /**
     * Confirm before touching a live credential.
     *
     * Laravel's default only prompts when the *app* is in production. That is
     * the wrong question here: issuing or revoking a partner's **production**
     * credential is equally irreversible when run from a staging box pointed
     * at the production database, which is exactly how these commands get run.
     * So either being production is enough to require an explicit --force.
     */
    protected function confirmSensitiveOperation(string $warning, PartnerEnvironment $environment): bool
    {
        return $this->confirmToProceed(
            $warning,
            fn () => $environment->isProduction() || app()->isProduction(),
        );
    }

    protected function resolveEnvironment(): ?PartnerEnvironment
    {
        $value = (string) $this->option('environment');

        $environment = PartnerEnvironment::tryFrom($value);

        if ($environment === null) {
            $this->components->error(
                "Unknown environment '{$value}'. Expected one of: ".implode(', ', PartnerEnvironment::values()).'.'
            );
        }

        return $environment;
    }

    protected function resolveClient(string $slug, PartnerEnvironment $environment): ?PartnerIntegrationClient
    {
        $client = PartnerIntegrationClient::query()
            ->where('slug', $slug)
            ->where('environment', $environment->value)
            ->first();

        if ($client === null) {
            $this->components->error("No integration client '{$slug}' in {$environment->value}.");
        }

        return $client;
    }

    /**
     * Print a freshly issued credential.
     *
     * Written to stdout only, never to the log: the plaintext exists once, and
     * a copy in a log file would outlive the operator's terminal.
     */
    protected function displayToken(IssuedPartnerToken $issued, PartnerIntegrationClient $client): void
    {
        $this->newLine();
        $this->components->info('API token issued — copy it now, it cannot be shown again.');
        $this->newLine();
        $this->line('  '.$issued->plainTextToken);
        $this->newLine();

        $this->components->twoColumnDetail('Client', $client->reference());
        $this->components->twoColumnDetail('Company', (string) $client->b2b_id);
        $this->components->twoColumnDetail('Token name', $issued->token->name);
        $this->components->twoColumnDetail('Abilities', implode(', ', $issued->token->abilityValues()));
        $this->components->twoColumnDetail(
            'Expires',
            $issued->token->expires_at?->toDayDateTimeString() ?? 'never (rotate on demand)',
        );
        $this->newLine();
        $this->components->warn('Send it over a channel the partner controls. Rotate it if it is ever pasted anywhere else.');
    }

    /**
     * @return list<string>|null null means "every ability"
     */
    protected function parseAbilities(): ?array
    {
        $raw = $this->option('abilities');

        if ($raw === null || trim((string) $raw) === '') {
            return null;
        }

        return collect(explode(',', (string) $raw))
            ->map(fn (string $ability) => trim($ability))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
