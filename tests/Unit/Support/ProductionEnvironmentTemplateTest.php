<?php

namespace Tests\Unit\Support;

use Tests\TestCase;

/**
 * The 'documents' disk holds private customer documents and defaults to
 * 'local' when DOCUMENTS_FILESYSTEM_DRIVER is unset, which puts them on the
 * app server with no backup. provision.sh seeds a new server's .env from this
 * template, so the template is where that choice has to be made explicitly
 * rather than inherited from a config default nobody reads.
 */
class ProductionEnvironmentTemplateTest extends TestCase
{
    public function test_documents_filesystem_driver_is_set_explicitly(): void
    {
        $this->assertSame('s3', $this->value('DOCUMENTS_FILESYSTEM_DRIVER'));
    }

    public function test_documents_disk_is_configured_independently_of_the_default_disk(): void
    {
        $this->assertArrayHasKey('documents', config('filesystems.disks'));
        $this->assertNotSame('documents', config('filesystems.default'));
    }

    /**
     * Reads an uncommented assignment out of the production .env template. A
     * commented-out or empty value fails, since either one leaves the driver
     * falling back to the config default.
     */
    private function value(string $key): ?string
    {
        $path = base_path('deploy/env.production.example');

        $this->assertFileExists($path);

        foreach (preg_split('/\R/', (string) file_get_contents($path)) ?: [] as $line) {
            if (preg_match('/^'.preg_quote($key, '/').'=(.+)$/', trim($line), $matches) === 1) {
                return trim($matches[1], " \t\"'");
            }
        }

        return null;
    }
}
