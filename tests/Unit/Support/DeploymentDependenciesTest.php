<?php

namespace Tests\Unit\Support;

use App\Modules\UserProfile\Order\Services\WorkshopQuotationService;
use Tests\TestCase;

/**
 * Facts about the provisioned host that the application silently depends on,
 * where losing them fails a feature without failing the portal.
 *
 * Poppler: Gutachten extraction shells out to pdftotext and pdfimages, the
 * application's only OS-level binary dependency. A host without them fails
 * every extraction while the rest of the portal stays healthy, so the omission
 * is invisible until an admin reports missing damage images.
 *
 * max_file_uploads: the workshop quotation form's image cap is defined relative
 * to it, so provisioning and WorkshopQuotationService have to agree.
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
     * PHP's default max_file_uploads is 20 and provisioning used to leave it
     * there, which made a number the workshop form depends on implicit. Pinned
     * now, so the invariant below is a property of what we ship rather than of
     * whichever PHP the host happened to install.
     */
    public function test_provisioning_pins_max_file_uploads(): void
    {
        $this->assertGreaterThan(
            0,
            $this->provisionedMaxFileUploads(),
            'deploy/provision.sh must set max_file_uploads explicitly.',
        );
    }

    /**
     * The invariant the workshop form's image cap rests on.
     *
     * PHP keeps the first max_file_uploads files of a multipart body and
     * discards the rest before any application code runs — no UPLOAD_ERR_*
     * marker, every text field intact — so the loss cannot be detected from
     * PHP. Holding the application cap strictly below the provisioned value is
     * what turns a truncated submission into a refusal: it always arrives
     * carrying max_file_uploads images, which is over the cap.
     *
     * If this fails, the feature can silently drop a workshop's photos.
     */
    public function test_the_workshop_image_cap_stays_below_the_provisioned_max_file_uploads(): void
    {
        $provisioned = $this->provisionedMaxFileUploads();

        $this->assertLessThan(
            $provisioned,
            WorkshopQuotationService::MAX_ADDITIONAL_IMAGES_TOTAL,
            "MAX_ADDITIONAL_IMAGES_TOTAL must stay below the provisioned max_file_uploads ({$provisioned}), "
                .'or PHP truncates a submission without the application being able to tell.',
        );
    }

    /**
     * The effective assignment, not a mention of the directive in the comment
     * that explains it: `;` opens an ini comment, so anchoring at the start of
     * the line skips those.
     */
    private function provisionedMaxFileUploads(): int
    {
        $matched = preg_match(
            '/^\s*max_file_uploads\s*=\s*(\d+)\s*$/m',
            $this->script('provision.sh'),
            $match,
        );

        $this->assertSame(1, $matched, 'deploy/provision.sh sets no max_file_uploads value.');

        return (int) $match[1];
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
