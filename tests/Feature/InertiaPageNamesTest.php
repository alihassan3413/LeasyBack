<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Every page the backend renders must exist under exactly that name. The
 * comparison is against the real directory listing, so a case mismatch fails
 * here on macOS too — the filesystem would forgive it locally, while Vite's
 * page glob and the Linux server do not (the page then renders blank).
 */
class InertiaPageNamesTest extends TestCase
{
    public function test_every_rendered_inertia_page_exists_with_exactly_that_name(): void
    {
        $pages = collect(File::allFiles(resource_path('js/pages')))
            ->map(fn ($file) => str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getRelativePathname(), 0, -strlen('.vue'))))
            ->all();

        $rendered = collect(File::allFiles(app_path()))
            ->flatMap(function ($file) {
                preg_match_all("/Inertia::render\\(\\s*'([^']+)'/", $file->getContents(), $matches);

                return $matches[1];
            })
            ->unique();

        $this->assertNotEmpty($rendered);

        foreach ($rendered as $component) {
            $this->assertContains($component, $pages, "Inertia renders '{$component}', but no page file has exactly that name.");
        }
    }
}
