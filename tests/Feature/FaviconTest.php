<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The favicon is a plain file under public/ referenced by a <link> tag in the
 * root Blade layout. Nothing at build time ties the two together, and the
 * previous placeholder was a zero-byte favicon.ico that browsers accept without
 * complaint — so an empty or missing icon fails silently. Both ends are pinned here.
 */
class FaviconTest extends TestCase
{
    private const FAVICON = 'favicon.png';

    public function test_the_favicon_exists_and_is_a_non_empty_png(): void
    {
        $path = public_path(self::FAVICON);

        $this->assertFileExists($path);
        $this->assertGreaterThan(512, (int) filesize($path), 'The favicon looks empty or truncated.');
        $this->assertSame("\x89PNG", substr((string) file_get_contents($path, length: 8), 0, 4), 'The favicon is not a PNG.');
    }

    public function test_the_favicon_is_square_and_sized_as_the_layout_declares(): void
    {
        [$width, $height] = (array) getimagesize(public_path(self::FAVICON));

        $this->assertSame($width, $height, 'The favicon must be square or browsers will squash it.');
        $this->assertSame(32, $width, 'The layout declares sizes="32x32"; update both together.');
    }

    public function test_the_layout_links_the_favicon(): void
    {
        $layout = (string) file_get_contents(resource_path('views/app.blade.php'));

        $this->assertStringContainsString("asset('".self::FAVICON."')", $layout, 'app.blade.php no longer links the favicon.');
        $this->assertStringContainsString('rel="icon"', $layout);
    }
}
