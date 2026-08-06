<?php

namespace App\Console\Commands\Partner;

use App\Modules\PartnerApi\Support\PartnerApiDocsComposer;
use App\Modules\PartnerApi\Support\PartnerEventCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/**
 * Builds the partner-facing API reference.
 *
 * The one entry point. `scribe:generate --config=scribe_partner` on its own
 * produces the endpoint reference and nothing else — this command composes the
 * generated prose around it, writes the machine-readable event catalogue, runs
 * the generator, and reports where everything landed.
 *
 * Three things are worth knowing about how it works:
 *
 * 1. **`intro_text` is set at runtime, not in the config file.** The intro is
 *    composed from the routing table, the enums and `config/partner_api.php`,
 *    and putting that in a config file would freeze it into `config:cache`.
 *    Scribe reads the value when it renders, in this same process, so the
 *    override lands.
 * 2. **`append.md` is written before the generator runs.** Scribe reads it and
 *    never writes it, so it is ours to own; its headings end up in the sidebar
 *    alongside the endpoint groups.
 * 3. **`--force` is always passed.** Every file under `.scribe_partner/` is
 *    generated, so preserving a manual edit would be preserving a defect: the
 *    whole point of this phase is that documentation cannot drift from the code
 *    it documents.
 *
 * Nothing is written into `public/`. The output is one private directory, which
 * is served to staff by the authenticated routes in `routes/partner_docs.php`
 * and handed to a partner as a zip.
 */
class BuildPartnerApiDocs extends Command
{
    protected $signature = 'partner:docs
                            {--skip-generate : Compose the Markdown and events.json without running Scribe}';

    protected $description = 'Build the partner-facing API reference (HTML, OpenAPI, Postman, events.json)';

    public function handle(): int
    {
        $composer = new PartnerApiDocsComposer(base_path('docs/partner-api/examples'));

        $scribeDir = base_path('.scribe_partner');
        $outputPath = base_path((string) config('scribe_partner.static.output_path'));

        File::ensureDirectoryExists($scribeDir);
        File::put($scribeDir.'/append.md', $composer->append()."\n");
        $this->components->info('Composed .scribe_partner/append.md');

        config(['scribe_partner.intro_text' => $composer->intro()]);

        if ($this->option('skip-generate')) {
            $this->components->warn('Skipping scribe:generate (--skip-generate).');

            return self::SUCCESS;
        }

        $exitCode = Artisan::call('scribe:generate', [
            '--config' => 'scribe_partner',
            '--force' => true,
        ], $this->getOutput());

        if ($exitCode !== self::SUCCESS) {
            $this->components->error('scribe:generate failed.');

            return $exitCode;
        }

        // After the generator, so a failed run cannot leave a stale catalogue
        // sitting next to no documentation.
        $this->writeEventCatalogue($outputPath);
        $this->copyBrandAssets($outputPath);

        $this->report($outputPath);

        return self::SUCCESS;
    }

    /**
     * The machine-readable half of the event documentation.
     *
     * Pretty-printed and with slashes unescaped so a partner can diff two
     * releases of it and read the diff.
     */
    private function writeEventCatalogue(string $outputPath): void
    {
        File::ensureDirectoryExists($outputPath);

        File::put(
            $outputPath.'/events.json',
            json_encode(
                PartnerEventCatalog::toArray(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            )."\n",
        );

        $this->components->info('Wrote events.json');
    }

    /**
     * Scribe copies its own theme assets; the logo is ours.
     */
    private function copyBrandAssets(string $outputPath): void
    {
        $logo = public_path('leasyback-logo.svg');

        if (! File::exists($logo)) {
            $this->components->warn('public/leasyback-logo.svg is missing — the docs will render without a logo.');

            return;
        }

        File::ensureDirectoryExists($outputPath.'/images');
        File::copy($logo, $outputPath.'/images/leasyback-logo.svg');
    }

    private function report(string $outputPath): void
    {
        $this->newLine();
        $this->components->info('Partner API reference built.');

        $this->components->twoColumnDetail('<fg=green>HTML</>', $outputPath.'/index.html');
        $this->components->twoColumnDetail('<fg=green>OpenAPI</>', $outputPath.'/openapi.yaml');
        $this->components->twoColumnDetail('<fg=green>Postman</>', $outputPath.'/collection.json');
        $this->components->twoColumnDetail('<fg=green>Events</>', $outputPath.'/events.json');

        $this->newLine();
        $this->line('  Served to signed-in admins at <options=bold>'.url('/partner-api/docs').'</>');
        $this->line('  Hand to a partner with: <options=bold>zip -r partner-api-docs.zip '
            .str_replace(base_path().'/', '', $outputPath).'</>');
        $this->newLine();
    }
}
