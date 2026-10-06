<?php

namespace App\Support\LegacyImport;

use RuntimeException;

/**
 * Read-only access to the Base44 CSV export. The files are never modified;
 * rows come back as header-keyed string maps.
 */
final class LegacyExport
{
    /** Datasets the import cannot run without. */
    private const REQUIRED = ['kunde', 'users', 'fahrzeug', 'auftrag'];

    /** @var array<string, list<string>> */
    private const KEY_COLUMNS = [
        'kunde' => ['id', 'firmenname', 'aktiv'],
        'users' => ['email', 'role', 'status', 'kunde_id'],
        'fahrzeug' => ['id', 'kunde_id', 'kennzeichen', 'hersteller'],
        'auftrag' => ['id', 'kunde_id', 'typ', 'status', 'fahrzeug_ids'],
        'kommentar' => ['id', 'auftrag_id', 'text'],
        'historie' => ['id', 'auftrag_id', 'neuer_status'],
        'dateianhang' => ['id', 'auftrag_id', 'speicherort'],
        'lead' => ['id'],
        'einladung' => ['id', 'kunde_id', 'email'],
        'benachrichtigung' => ['id'],
    ];

    /** @var array<string, list<array<string, string>>> */
    private array $cache = [];

    /**
     * @param  array<string, string>  $files  dataset => file name
     */
    public function __construct(private readonly string $directory, private readonly array $files) {}

    public static function fromConfig(?string $directory = null): self
    {
        $directory ??= config('legacy_import.source_path');

        if (! is_string($directory) || $directory === '') {
            throw new RuntimeException('No export folder given. Pass --source= or set LEGACY_IMPORT_SOURCE_PATH.');
        }

        return new self($directory, config('legacy_import.files'));
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function isInsideRepository(): bool
    {
        $real = realpath($this->directory);

        return $real !== false && str_starts_with(str_replace('\\', '/', $real), str_replace('\\', '/', base_path()));
    }

    public function exists(string $dataset): bool
    {
        return is_file($this->path($dataset));
    }

    /**
     * @return list<array<string, string>>
     */
    public function rows(string $dataset): array
    {
        if (isset($this->cache[$dataset])) {
            return $this->cache[$dataset];
        }

        if (! $this->exists($dataset)) {
            if (in_array($dataset, self::REQUIRED, true)) {
                throw new RuntimeException("Required export file missing: {$this->files[$dataset]}");
            }

            return $this->cache[$dataset] = [];
        }

        return $this->cache[$dataset] = $this->read($dataset);
    }

    private function path(string $dataset): string
    {
        if (! isset($this->files[$dataset])) {
            throw new RuntimeException("Unknown dataset '{$dataset}'.");
        }

        return rtrim($this->directory, '/\\').DIRECTORY_SEPARATOR.$this->files[$dataset];
    }

    /**
     * @return list<array<string, string>>
     */
    private function read(string $dataset): array
    {
        $handle = fopen($this->path($dataset), 'rb');

        if ($handle === false) {
            throw new RuntimeException("Cannot open {$this->files[$dataset]}.");
        }

        try {
            $header = fgetcsv($handle, 0, ',', '"', '');

            if ($header === false || $header === [null]) {
                return [];
            }

            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
            $header = array_map(fn ($column) => trim((string) $column), $header);

            $missing = array_diff(self::KEY_COLUMNS[$dataset] ?? [], $header);

            if ($missing !== []) {
                throw new RuntimeException("{$this->files[$dataset]} lacks expected columns: ".implode(', ', $missing));
            }

            $rows = [];

            while (($record = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if ($record === [null]) {
                    continue;
                }

                $record = array_map(fn ($value) => (string) $value, array_pad(array_slice($record, 0, count($header)), count($header), ''));
                $rows[] = array_combine($header, $record);
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }
}
