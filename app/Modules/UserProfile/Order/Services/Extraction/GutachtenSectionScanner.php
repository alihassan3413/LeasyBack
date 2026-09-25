<?php

namespace App\Modules\UserProfile\Order\Services\Extraction;

use App\Modules\UserProfile\Order\Data\GutachtenRow;
use App\Modules\UserProfile\Order\Data\PdfPageText;

class GutachtenSectionScanner
{
    private const MODE_NONE = '';

    private const MODE_DAMAGE = 'damage';

    private const MODE_MISSING = 'missing_parts';

    private const LOOK_AHEAD = 3;

    public function scan(array $pages): array
    {
        $entries = $this->entries($pages);
        $rows = [];
        $seen = [];
        $mode = self::MODE_NONE;
        $appendix = false;
        $limit = (int) config('gutachten.limits.rows');

        foreach ($entries as $index => $entry) {
            $line = $entry['text'];

            if ($this->matchesAny($line, 'appendix')) {
                $appendix = true;
                $mode = self::MODE_NONE;

                continue;
            }

            if ($appendix) {
                continue;
            }

            if ($this->matchesAny($line, 'stop')) {
                $mode = self::MODE_NONE;

                continue;
            }

            if ($this->matchesAny($line, 'damage')) {
                $mode = self::MODE_DAMAGE;

                continue;
            }

            if ($this->matchesAny($line, 'missing_parts')) {
                $mode = self::MODE_MISSING;

                continue;
            }

            if ($mode === self::MODE_NONE || $this->isNoise($line) || count($rows) >= $limit) {
                continue;
            }

            $amounts = GutachtenAmount::matchAll($line);

            if (count($amounts) < 2) {
                continue;
            }

            $rawCore = rtrim($this->collapse(mb_substr($line, 0, $this->characterOffset($line, $amounts[0]['offset']))), '€ ');
            $core = $this->clean($rawCore);

            if ($core === '' || preg_match('/^(?:Summe\b|Nr\.?\b|Fehlteil\b)/iu', $core) === 1) {
                continue;
            }

            $values = array_map(fn (array $amount) => $amount['value'], $amounts);
            $numbered = preg_match('/^(\d+)\s+(.+)$/u', $core, $matches) === 1;

            if ($mode === self::MODE_MISSING && ! $numbered) {
                $key = 'missing:'.mb_strtolower($core).':'.$values[count($values) - 1];

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $rows[] = new GutachtenRow($entry['page'], null, $core, $line, $values, true);

                continue;
            }

            if (! $numbered) {
                continue;
            }

            $rowNumber = (int) $matches[1];
            $key = 'damage:'.$rowNumber;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $rows[] = new GutachtenRow(
                $entry['page'],
                $rowNumber,
                $this->assemble($matches[2], $entries, $index, preg_match('/[-–—]\s*$/u', $rawCore) === 1),
                $line,
                $values,
                $mode === self::MODE_MISSING,
            );
        }

        return $rows;
    }

    private function assemble(string $raw, array $entries, int $index, bool $wrappedOnDash): string
    {
        $glue = $wrappedOnDash ? ' - ' : ' ';
        $text = $this->clean($raw);
        $previous = $entries[$index - 1]['text'] ?? '';

        if ($this->dashCount($text) === 0 && $this->dashCount($previous) >= 1 && $this->isContinuation($previous)) {
            $text = $this->clean($text.' '.$previous);
        }

        if ($this->dashCount($text) >= 2 && preg_match('#(?:/|\+|und)\s*$#iu', $text) !== 1) {
            return $text;
        }

        $following = [];

        for ($offset = 1; $offset <= self::LOOK_AHEAD; $offset++) {
            $candidate = $entries[$index + $offset]['text'] ?? null;

            if ($candidate === null || ! $this->isContinuation($candidate) || $this->startsNewRow($candidate)) {
                break;
            }

            $following[] = $candidate;
        }

        return $following === [] ? $text : $this->clean($text.$glue.implode(' ', $following));
    }

    private function isContinuation(string $line): bool
    {
        return $line !== ''
            && ! $this->isNoise($line)
            && ! GutachtenAmount::contains($line)
            && ! $this->matchesAny($line, 'damage')
            && ! $this->matchesAny($line, 'stop')
            && ! $this->matchesAny($line, 'missing_parts')
            && ! $this->matchesAny($line, 'appendix');
    }

    private function startsNewRow(string $line): bool
    {
        return preg_match('/^\d+\s+/u', $line) === 1;
    }

    private function entries(array $pages): array
    {
        $entries = [];

        foreach ($pages as $page) {
            if (! $page instanceof PdfPageText) {
                continue;
            }

            foreach ($page->lines as $line) {
                $clean = $this->clean($line);

                if ($clean !== '') {
                    $entries[] = ['page' => $page->pageNumber, 'text' => $clean];
                }
            }
        }

        return $entries;
    }

    private function matchesAny(string $line, string $section): bool
    {
        foreach ((array) config("gutachten.sections.{$section}") as $pattern) {
            if (preg_match($pattern, $line) === 1) {
                return true;
            }
        }

        return false;
    }

    private function isNoise(string $line): bool
    {
        foreach ((array) config('gutachten.noise') as $pattern) {
            if (preg_match($pattern, $line) === 1) {
                return true;
            }
        }

        return false;
    }

    private function dashCount(string $value): int
    {
        return preg_match_all('/\s+-\s+/u', $value);
    }

    private function characterOffset(string $line, int $byteOffset): int
    {
        return mb_strlen(substr($line, 0, $byteOffset));
    }

    private function collapse(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    private function clean(string $value): string
    {
        return trim(preg_replace('/^[-–—|:;\s]+|[-–—|:;\s]+$/u', '', $this->collapse($value)) ?? '');
    }
}
