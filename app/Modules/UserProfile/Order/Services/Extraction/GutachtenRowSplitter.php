<?php

namespace App\Modules\UserProfile\Order\Services\Extraction;

use App\Modules\UserProfile\Order\Data\AppraisalProposalLine;
use App\Modules\UserProfile\Order\Data\GutachtenRow;

class GutachtenRowSplitter
{
    public function split(GutachtenRow $row): ?AppraisalProposalLine
    {
        $amounts = array_values(array_filter($row->amounts, fn (string $amount) => GutachtenAmount::isPositive($amount)));

        if ($amounts === []) {
            return null;
        }

        $original = $amounts[0];
        $last = $amounts[count($amounts) - 1];
        $chargeable = count($amounts) > 1 && bccomp($last, $original, 2) !== 0 ? $last : null;

        $parts = $row->isMissingPart ? $this->missingPart($row->text) : $this->damageRow($row->text);

        if ($parts === null) {
            return null;
        }

        return new AppraisalProposalLine(
            component: $this->limit($parts['component'], 'component'),
            originalAmountNet: $original,
            chargeableAmountNet: $chargeable,
            damageDescription: $parts['damage'] === null ? null : $this->limit($parts['damage'], 'damage_description'),
            repairMethod: $parts['repair_method'] === null ? null : $this->limit($parts['repair_method'], 'repair_method'),
            pageNumber: $row->pageNumber,
            sourceText: $this->limit($row->sourceText, 'source_text'),
            confidence: (float) config("gutachten.confidence.{$parts['confidence']}"),
            damageNumber: $row->rowNumber,
        );
    }

    private function missingPart(string $text): ?array
    {
        $body = $this->clean(preg_replace((string) config('gutachten.missing_part.strip_prefix'), '', $text) ?? $text);

        if ($body === '') {
            return null;
        }

        return [
            'component' => $body,
            'damage' => (string) config('gutachten.missing_part.suffix'),
            'repair_method' => (string) config('gutachten.missing_part.repair_method'),
            'confidence' => 'missing_part',
        ];
    }

    private function damageRow(string $text): ?array
    {
        $body = $this->clean(preg_replace('/^\d+\s+/u', '', $text) ?? $text);

        if ($body === '') {
            return null;
        }

        $parts = array_values(array_filter(array_map($this->clean(...), preg_split('/\s+-\s+/u', $body) ?: [])));

        if (count($parts) >= 3) {
            $repairMethod = array_pop($parts);
            $damage = array_pop($parts);
            [$component, $damage] = $this->rejoinSplitGroup($this->stripGroup(implode(' - ', $parts)), $damage);

            return [
                'component' => $component,
                'damage' => $damage,
                'repair_method' => $repairMethod,
                'confidence' => 'structured',
            ];
        }

        $keyword = $this->keywordSplit($body);

        if ($keyword !== null) {
            return $keyword;
        }

        return [
            'component' => $this->stripGroup($body),
            'damage' => null,
            'repair_method' => null,
            'confidence' => 'fallback',
        ];
    }

    private function keywordSplit(string $body): ?array
    {
        $repair = $this->firstMatch($body, (array) config('gutachten.repair_terms'));

        if ($repair === null) {
            return null;
        }

        $before = $this->clean(mb_substr($body, 0, $repair['offset']));
        $after = $this->clean(mb_substr($body, $repair['offset'] + mb_strlen($repair['text'])));
        $damage = $this->firstMatch($before, (array) config('gutachten.damage_terms'));

        if ($damage === null) {
            return null;
        }

        $component = $this->stripGroup($this->clean(mb_substr($before, 0, $damage['offset']).' '.$after));

        if ($component === '') {
            return null;
        }

        return [
            'component' => $component,
            'damage' => $damage['text'],
            'repair_method' => $repair['text'],
            'confidence' => 'keyword',
        ];
    }

    private function firstMatch(string $haystack, array $terms): ?array
    {
        foreach ($terms as $term) {
            if (preg_match('/'.preg_quote((string) $term, '/').'/iu', $haystack, $matches, PREG_OFFSET_CAPTURE) === 1) {
                return ['text' => $matches[0][0], 'offset' => mb_strlen(substr($haystack, 0, $matches[0][1]))];
            }
        }

        return null;
    }

    private function rejoinSplitGroup(string $component, string $damage): array
    {
        foreach ((array) config('gutachten.component_groups') as $group) {
            $segments = explode('/', (string) $group);

            if (count($segments) !== 2 || $segments[0] === '' || $segments[1] === '') {
                continue;
            }

            $head = $segments[0].'/';
            $tail = $segments[1];

            if (! str_starts_with(mb_strtolower($component), mb_strtolower($head)) || ! str_starts_with(mb_strtolower($damage), mb_strtolower($tail))) {
                continue;
            }

            $remainingComponent = $this->clean(mb_substr($component, mb_strlen($head)));
            $remainingDamage = $this->clean(mb_substr($damage, mb_strlen($tail)));

            if ($remainingComponent === '' || $remainingDamage === '') {
                continue;
            }

            return [$remainingComponent, $remainingDamage];
        }

        return [$component, $damage];
    }

    private function stripGroup(string $value): string
    {
        $normalized = $this->clean($value);
        $groups = (array) config('gutachten.component_groups');

        usort($groups, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        foreach ($groups as $group) {
            if (! str_starts_with(mb_strtolower($normalized), mb_strtolower((string) $group))) {
                continue;
            }

            $remainder = $this->clean(mb_substr($normalized, mb_strlen((string) $group)));

            if ($remainder === '' || preg_match('/^(?:links|rechts|vorn|hinten)$/iu', $remainder) === 1) {
                return $normalized;
            }

            return str_contains(mb_strtolower((string) $group), mb_strtolower($remainder)) ? (string) $group : $remainder;
        }

        return $normalized;
    }

    private function limit(string $value, string $key): string
    {
        $limit = (int) config("gutachten.limits.{$key}");

        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit) : $value;
    }

    private function clean(string $value): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', $value) ?? '';

        return trim(preg_replace('/^[-–—|:;\s]+|[-–—|:;\s]+$/u', '', $collapsed) ?? '');
    }
}
