<?php

namespace App\Console\Commands;

use App\Models\InspectionStation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class SyncInspectionStations extends Command
{
    protected $signature = 'stations:sync
                            {--file= : Path to the JSON source (defaults to database/data/inspection-stations.json)}
                            {--prune : Deactivate stations present in the database but absent from the source}
                            {--dry-run : Report what would change without writing anything}';

    protected $description = 'Create or update the DEKRA / TÜV SÜD inspection stations from the bundled JSON source';

    /** @var list<string> */
    private const COLUMNS = ['provider', 'name', 'strasse', 'plz', 'ort', 'bundesland', 'land'];

    public function handle(): int
    {
        $path = $this->option('file') ?: database_path('data/inspection-stations.json');

        if (! File::exists($path)) {
            $this->components->error("Source file not found: {$path}");

            return self::FAILURE;
        }

        $stations = json_decode(File::get($path), true);

        if (! is_array($stations) || $stations === []) {
            $this->components->error("Source file is not a non-empty JSON array: {$path}");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $existing = InspectionStation::pluck('station_id')->flip();

        $created = 0;
        $updated = 0;
        $unchanged = 0;

        foreach ($stations as $index => $station) {
            $stationId = $station['station_id'] ?? null;

            if (! is_string($stationId) || $stationId === '') {
                $this->components->error("Entry #{$index} is missing a station_id — aborting without writing.");

                return self::FAILURE;
            }

            $attributes = [
                'provider' => $station['provider'],
                'name' => $station['name'],
                'strasse' => $station['strasse'],
                'plz' => $this->normalizePlz((string) $station['plz']),
                'ort' => $station['ort'],
                'bundesland' => $station['bundesland'] ?? null,
                'land' => $station['land'] ?? 'de',
                'is_active' => true,
            ];

            $record = InspectionStation::find($stationId);

            if (! $record) {
                $created++;

                if (! $dryRun) {
                    InspectionStation::create([...$attributes, 'station_id' => $stationId]);
                }

                continue;
            }

            $record->fill($attributes);

            if (! $record->isDirty()) {
                $unchanged++;

                continue;
            }

            $updated++;

            if (! $dryRun) {
                $record->save();
            }
        }

        $sourceIds = array_column($stations, 'station_id');
        $orphans = $existing->keys()->diff($sourceIds);
        $pruned = 0;

        if ($this->option('prune') && $orphans->isNotEmpty()) {
            $pruned = $orphans->count();

            if (! $dryRun) {
                InspectionStation::whereIn('station_id', $orphans)->update(['is_active' => false]);
            }
        }

        $this->newLine();
        $this->components->twoColumnDetail('Source', $path);
        $this->components->twoColumnDetail('Created', (string) $created);
        $this->components->twoColumnDetail('Updated', (string) $updated);
        $this->components->twoColumnDetail('Unchanged', (string) $unchanged);
        $this->components->twoColumnDetail('Deactivated', $this->option('prune') ? (string) $pruned : 'skipped (--prune not set)');

        if (! $this->option('prune') && $orphans->isNotEmpty()) {
            $this->components->warn("{$orphans->count()} station(s) in the database are absent from the source. Re-run with --prune to deactivate them.");
        }

        if ($dryRun) {
            $this->components->info('Dry run — nothing was written.');
        }

        return self::SUCCESS;
    }

    /**
     * German postal codes are always five digits; the source data lost leading
     * zeros somewhere upstream (e.g. Halle arrives as "6126", not "06126").
     */
    private function normalizePlz(string $plz): string
    {
        $trimmed = trim($plz);

        return ctype_digit($trimmed) ? str_pad($trimmed, 5, '0', STR_PAD_LEFT) : $trimmed;
    }
}
