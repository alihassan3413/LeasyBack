<?php

namespace App\Modules\UserProfile\Order\Services\Extraction;

use App\Modules\UserProfile\Order\Data\ExtractedImage;
use App\Modules\UserProfile\Order\Data\PdfPageText;

class GutachtenDamageImageMapper
{
    public function map(array $pages, array $images): array
    {
        $captions = $this->captionsByPage($pages);
        $mapping = [];

        foreach ($this->imagesByPage($images) as $pageNumber => $pageImages) {
            foreach ($this->mapPage($captions[$pageNumber] ?? [], $pageImages) as $index => $damageNumber) {
                $mapping[$index] = $damageNumber;
            }
        }

        return $mapping;
    }

    private function mapPage(array $captions, array $images): array
    {
        $mapping = [];

        foreach ($images as $image) {
            $mapping[$image->index] = null;
        }

        if ($captions === []) {
            return $mapping;
        }

        $distinct = array_values(array_unique($captions));

        if (count($distinct) === 1) {
            return array_fill_keys(array_keys($mapping), $distinct[0]);
        }

        if (count($captions) !== count($images)) {
            return $mapping;
        }

        foreach (array_values($images) as $position => $image) {
            $mapping[$image->index] = $captions[$position];
        }

        return $mapping;
    }

    private function captionsByPage(array $pages): array
    {
        $captions = [];

        foreach ($pages as $page) {
            if (! $page instanceof PdfPageText) {
                continue;
            }

            if (preg_match_all((string) config('gutachten.images.damage_number'), $page->text(), $matches) > 0) {
                $captions[$page->pageNumber] = array_map('intval', $matches[1]);
            }
        }

        return $captions;
    }

    private function imagesByPage(array $images): array
    {
        $byPage = [];

        foreach ($images as $image) {
            if ($image instanceof ExtractedImage && $image->pageNumber !== null) {
                $byPage[$image->pageNumber][] = $image;
            }
        }

        foreach ($byPage as $pageNumber => $pageImages) {
            usort($pageImages, fn (ExtractedImage $a, ExtractedImage $b) => $a->index <=> $b->index);
            $byPage[$pageNumber] = $pageImages;
        }

        return $byPage;
    }
}
