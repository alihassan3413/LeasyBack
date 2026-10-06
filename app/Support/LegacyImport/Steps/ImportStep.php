<?php

namespace App\Support\LegacyImport\Steps;

use App\Support\LegacyImport\ImportContext;

interface ImportStep
{
    public function name(): string;

    public function run(ImportContext $context): void;
}
