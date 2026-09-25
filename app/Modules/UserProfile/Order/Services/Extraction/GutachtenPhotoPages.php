<?php

namespace App\Modules\UserProfile\Order\Services\Extraction;

use App\Modules\UserProfile\Order\Data\PdfPageText;

class GutachtenPhotoPages
{
    public function detect(array $pages): ?array
    {
        $damagePages = [];
        $headingPage = null;
        $overviewPages = [];

        foreach ($pages as $page) {
            if (! $page instanceof PdfPageText) {
                continue;
            }

            foreach ($page->lines as $line) {
                $heading = $this->heading($line);

                if ($headingPage === null && $this->matchesAny($heading, 'damage_headings')) {
                    $headingPage = $page->pageNumber;
                }

                if ($this->matchesAny($heading, 'overview_headings')) {
                    $overviewPages[] = $page->pageNumber;
                }
            }

            if (preg_match((string) config('gutachten.images.damage_caption'), $page->text()) === 1) {
                $damagePages[] = $page->pageNumber;
            }
        }

        $allowed = array_merge($damagePages, $this->pagesFromHeading($pages, $headingPage, $overviewPages));

        if ($allowed === []) {
            return null;
        }

        $allowed = array_values(array_unique($allowed));
        sort($allowed);

        return $allowed;
    }

    private function pagesFromHeading(array $pages, ?int $headingPage, array $overviewPages): array
    {
        if ($headingPage === null) {
            return [];
        }

        $boundary = null;

        foreach ($overviewPages as $overviewPage) {
            if ($overviewPage > $headingPage && ($boundary === null || $overviewPage < $boundary)) {
                $boundary = $overviewPage;
            }
        }

        $allowed = [];

        foreach ($pages as $page) {
            if (! $page instanceof PdfPageText || $page->pageNumber < $headingPage || $page->lines === []) {
                continue;
            }

            if ($boundary !== null && $page->pageNumber >= $boundary) {
                continue;
            }

            $allowed[] = $page->pageNumber;
        }

        return $allowed;
    }

    private function heading(string $line): string
    {
        $collapsed = trim(preg_replace('/\s+/u', ' ', $line) ?? '');

        return trim(preg_replace('/^[»«>\-•\s]+|[:.\s]+$/u', '', $collapsed) ?? '');
    }

    private function matchesAny(string $line, string $key): bool
    {
        if ($line === '') {
            return false;
        }

        foreach ((array) config("gutachten.images.{$key}") as $pattern) {
            if (preg_match((string) $pattern, $line) === 1) {
                return true;
            }
        }

        return false;
    }
}
