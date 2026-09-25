<?php

namespace App\Modules\UserProfile\Order\Services\Extraction;

final class GutachtenAmount
{
    public const PATTERN = '/(?:€\s*)?(?:\d{1,3}(?:\.\d{3})+|\d+)\s*,\s*\d{2}(?:\s*(?:EUR|€))?/iu';

    public static function matchAll(string $line): array
    {
        if (preg_match_all(self::PATTERN, $line, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return [];
        }

        return array_map(
            fn (array $match) => ['value' => self::toDecimal($match[0]), 'offset' => $match[1], 'text' => $match[0]],
            $matches[0],
        );
    }

    public static function contains(string $line): bool
    {
        return preg_match(self::PATTERN, $line) === 1;
    }

    public static function toDecimal(string $amount): string
    {
        $normalized = preg_replace('/[^0-9,.-]/u', '', $amount) ?? '';
        $normalized = str_replace('.', '', $normalized);
        $normalized = str_replace(',', '.', $normalized);

        if (! is_numeric($normalized)) {
            return '0.00';
        }

        return bcadd($normalized, '0', 2);
    }

    public static function isPositive(string $amount): bool
    {
        return bccomp($amount, '0.00', 2) === 1;
    }
}
