<?php

namespace Tests\Unit\Support;

use Tests\TestCase;

/**
 * Gutachten extraction shells out to poppler's pdftotext and pdfimages, the
 * application's only OS-level binary dependency. A host without them fails
 * every extraction while the rest of the portal stays healthy, so the omission
 * is invisible until an admin reports missing damage images. These assertions
 * keep provisioning and the deploy smoke check from losing it again.
 */
class DeploymentDependenciesTest extends TestCase
{
    public function test_provisioning_installs_poppler_utils(): void
    {
        $this->assertStringContainsString('poppler-utils', $this->script('provision.sh'));
    }

    public function test_deployment_smoke_checks_both_poppler_binaries(): void
    {
        $deploy = $this->script('deploy.sh');

        $this->assertStringContainsString('pdftotext', $deploy);
        $this->assertStringContainsString('pdfimages', $deploy);
        $this->assertStringContainsString('poppler-utils', $deploy);
    }

    /**
     * Comments are stripped so the assertions cover the executable part of the
     * script rather than a mention of poppler in a comment above it.
     */
    private function script(string $name): string
    {
        $path = base_path("deploy/{$name}");

        $this->assertFileExists($path);

        $lines = preg_split('/\R/', (string) file_get_contents($path)) ?: [];

        return implode("\n", array_filter(
            $lines,
            fn (string $line) => ! str_starts_with(ltrim($line), '#'),
        ));
    }
}
