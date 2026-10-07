<?php

namespace App\Console\Commands\LegacyImport;

use App\Support\LegacyImport\LegacyExport;
use App\Support\LegacyImport\LegacyValue;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Diagnoses why Base44 document downloads fail, without storing a byte and
 * without printing a full URL (hosts are shown; ids and file names are masked).
 *
 * Each URL is requested three ways: exactly like the importer (https only,
 * host allow-list, redirects only to allowed hosts), without following
 * redirects (to see where it points and whether it asks for a login), and with
 * unrestricted redirects (to see whether our redirect protection is what stops
 * it). Only the first byte of a body is ever read.
 */
class LegacyProbeDocumentsCommand extends AbstractLegacyCommand
{
    protected $signature = 'legacy:probe-documents
        {--source= : Folder with the Base44 CSV export (default: LEGACY_IMPORT_SOURCE_PATH)}
        {--first : Probe only the first URL}
        {--limit= : Probe at most this many URLs}
        {--timeout=20 : Seconds per request}
        {--quiet-lines : Print the summary only, not one masked line per URL}';

    protected $description = 'Probe the Base44 document URLs (nothing is stored, URLs are masked)';

    /** @var array<string, int> */
    private array $classes = [];

    /** @var array<string, int> */
    private array $statuses = [];

    /** @var array<string, int> */
    private array $redirectHosts = [];

    /** @var array<string, int> */
    private array $contentTypes = [];

    private int $authHints = 0;

    public function handle(): int
    {
        try {
            $export = LegacyExport::fromConfig($this->option('source') ?: null);

            if (! is_dir($export->directory())) {
                throw new \RuntimeException('export folder not found');
            }

            $urls = $this->urls($export);
        } catch (Throwable $e) {
            $this->error('Probe aborted: '.$e->getMessage());

            return self::FAILURE;
        }

        $limit = $this->option('first') ? 1 : (int) ($this->option('limit') ?: count($urls));
        $urls = array_slice($urls, 0, $limit);
        $timeout = max(1, (int) $this->option('timeout'));

        $this->info('Probing '.count($urls).' document URL(s). Nothing is stored; URLs are masked.');

        foreach ($urls as $n => $url) {
            $class = $this->probe($url, $timeout);
            $this->classes[$class] = ($this->classes[$class] ?? 0) + 1;

            if (! $this->option('quiet-lines')) {
                $this->line(sprintf('#%02d %s  ->  %s', $n + 1, $this->mask($url), $class));
            }
        }

        $this->summary();

        return self::SUCCESS;
    }

    /**
     * The URLs the importer would try: uploads of real orders and attachments
     * of comments that were not deleted.
     *
     * @return list<string>
     */
    private function urls(LegacyExport $export): array
    {
        $urls = [];

        foreach ($export->rows('dateianhang') as $row) {
            if (! str_starts_with($row['auftrag_id'], 'FAHRZEUG_IMPORT_') && LegacyValue::text($row['speicherort']) !== null) {
                $urls[] = trim($row['speicherort']);
            }
        }

        foreach ($export->rows('kommentar') as $comment) {
            if (LegacyValue::bool($comment['geloescht']) === true) {
                continue;
            }

            foreach ((array) LegacyValue::json($comment['anhaenge']) as $item) {
                if (is_array($item) && ! empty($item['url'])) {
                    $urls[] = (string) $item['url'];
                }
            }
        }

        return array_values(array_unique($urls));
    }

    private function probe(string $url, int $timeout): string
    {
        if (! $this->hostAllowed($url)) {
            return 'BLOCKED by our host allow-list (not https, or host not in legacy_import.document_hosts)';
        }

        // 1. exactly as the importer
        $importer = $this->request($url, $timeout, [
            'max' => 3,
            'protocols' => ['https'],
            'on_redirect' => function ($request, $response, $uri) {
                if (! $this->hostAllowed((string) $uri)) {
                    throw new \RuntimeException('redirect_to_host_not_allowed');
                }
            },
        ]);

        // 2. without following redirects: where does it point, does it want a login?
        $direct = $this->request($url, $timeout, false);
        $this->note($direct);

        // 3. with unrestricted redirects: would our redirect protection be the only obstacle?
        $open = ($importer['ok'] ?? false) ? null : $this->request($url, $timeout, ['max' => 5, 'track_redirects' => true]);

        $key = (string) ($importer['status'] ?? ($importer['error'] ?? 'no-response'));
        $this->statuses[$key] = ($this->statuses[$key] ?? 0) + 1;

        if ($importer['ok'] ?? false) {
            $this->contentTypes[$importer['type'] ?: 'unknown'] = ($this->contentTypes[$importer['type'] ?: 'unknown'] ?? 0) + 1;

            return 'OK (HTTP '.$importer['status'].')';
        }

        if (($importer['error'] ?? '') === 'redirect_to_host_not_allowed') {
            $target = ($open['final_host'] ?? null) ?: (parse_url((string) ($direct['location'] ?? ''), PHP_URL_HOST) ?: 'another host');

            return $open !== null && ($open['ok'] ?? false)
                ? "BLOCKED by our redirect protection: redirects to {$target} and would work (HTTP {$open['status']})"
                : 'BLOCKED by our redirect protection, and the redirect target does not serve it either'.($open && isset($open['status']) ? " (HTTP {$open['status']})" : '');
        }

        if (isset($importer['error'])) {
            return 'CONNECTION/TLS error: '.$importer['error'];
        }

        $status = (int) $importer['status'];
        $auth = in_array($status, [401, 403], true) || ($direct['www_authenticate'] ?? false);

        return match (true) {
            $auth => "AUTH required (HTTP {$status})",
            in_array($status, [404, 410], true) => "NOT FOUND / gone (HTTP {$status})",
            $status >= 500 => "Base44 server error (HTTP {$status})",
            default => "HTTP {$status}",
        };
    }

    /**
     * One request; only the first byte of a body is read.
     *
     * @param  array<string, mixed>|false  $redirects
     * @return array<string, mixed>
     */
    private function request(string $url, int $timeout, array|false $redirects): array
    {
        try {
            $response = Http::timeout($timeout)
                ->withOptions(['stream' => true, 'allow_redirects' => $redirects])
                ->get($url);
        } catch (Throwable $e) {
            return ['error' => $e instanceof \RuntimeException && str_contains($e->getMessage(), 'redirect_to_host_not_allowed')
                ? 'redirect_to_host_not_allowed'
                : class_basename($e)];
        }

        try {
            $response->toPsrResponse()->getBody()->read(1);
        } catch (Throwable) {
            // the status is what matters
        }

        $location = $response->header('Location');
        $history = $response->header('X-Guzzle-Redirect-History');
        $finalUrl = $history !== '' ? trim((string) last(explode(',', $history))) : '';

        return [
            'ok' => $response->successful(),
            'status' => $response->status(),
            'type' => strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0])),
            'location' => $location !== '' ? $location : null,
            'www_authenticate' => $response->header('WWW-Authenticate') !== '',
            'final_host' => $finalUrl !== '' ? (string) parse_url($finalUrl, PHP_URL_HOST) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $direct
     */
    private function note(array $direct): void
    {
        if (isset($direct['www_authenticate']) && $direct['www_authenticate']) {
            $this->authHints++;
        }

        if (! empty($direct['location'])) {
            $host = (string) (parse_url($direct['location'], PHP_URL_HOST) ?: 'same-host');
            $this->redirectHosts[$host] = ($this->redirectHosts[$host] ?? 0) + 1;

            if (preg_match('~login|signin|sign-in|auth|sso|oauth~i', (string) parse_url($direct['location'], PHP_URL_PATH))) {
                $this->authHints++;
            }
        }
    }

    private function summary(): void
    {
        $this->newLine();
        $this->info('Result classes:');
        arsort($this->classes);
        $this->table(['Result', 'URLs'], array_map(fn ($k, $v) => [$k, $v], array_keys($this->classes), $this->classes));
        $this->line('Importer-equivalent request, by HTTP status / error: '.json_encode($this->statuses, JSON_UNESCAPED_SLASHES));
        $this->line('Redirects seen when not following them, by target host: '.($this->redirectHosts === [] ? 'none' : json_encode($this->redirectHosts, JSON_UNESCAPED_SLASHES)));
        $this->line('Content types of successful responses: '.($this->contentTypes === [] ? 'none' : json_encode($this->contentTypes, JSON_UNESCAPED_SLASHES)));
        $this->line('Signs of required authentication (WWW-Authenticate or a login-looking redirect): '.$this->authHints);
    }

    private function hostAllowed(string $url): bool
    {
        $parts = parse_url($url);
        $host = mb_strtolower($parts['host'] ?? '');

        if (($parts['scheme'] ?? '') !== 'https' || $host === '') {
            return false;
        }

        foreach (config('legacy_import.document_hosts') as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    /** Host stays; long ids and the file name do not. */
    private function mask(string $url): string
    {
        $parts = parse_url($url);
        $segments = array_values(array_filter(explode('/', (string) ($parts['path'] ?? '')), fn ($s) => $s !== ''));
        $last = array_key_last($segments);

        foreach ($segments as $i => $segment) {
            if ($i === $last) {
                $extension = strtolower(pathinfo($segment, PATHINFO_EXTENSION));
                $segments[$i] = '<file>'.(preg_match('/^[a-z0-9]{1,5}$/', $extension) ? '.'.$extension : '');
            } elseif (preg_match('/^[0-9a-f]{12,}$/i', $segment) || strlen($segment) > 20) {
                $segments[$i] = substr($segment, 0, 4).'…';
            }
        }

        return ($parts['scheme'] ?? '?').'://'.($parts['host'] ?? '?').'/'.implode('/', $segments);
    }
}
