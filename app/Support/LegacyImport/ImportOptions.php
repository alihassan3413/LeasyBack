<?php

namespace App\Support\LegacyImport;

final class ImportOptions
{
    /** Steps in dependency order. */
    public const STEPS = ['companies', 'books', 'users', 'vehicles', 'orders', 'history', 'messages', 'documents', 'archive'];

    /**
     * @param  list<string>  $steps
     */
    public function __construct(
        public readonly bool $dryRun,
        public readonly string $batchId,
        public readonly array $steps = self::STEPS,
    ) {}

    public function runs(string $step): bool
    {
        return in_array($step, $this->steps, true);
    }
}
