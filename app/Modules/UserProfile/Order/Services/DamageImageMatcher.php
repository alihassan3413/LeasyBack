<?php

namespace App\Modules\UserProfile\Order\Services;

use App\Modules\UserProfile\Order\Data\AppraisalExtractionProposal;
use App\Modules\UserProfile\Order\Data\AppraisalProposalLine;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Vehicle\Support\ReportDocumentImage;
use Illuminate\Support\Facades\DB;

class DamageImageMatcher
{
    public const STRATEGY_DAMAGE_NUMBER = 'damage_number';

    public const STRATEGY_TEXT = 'text';

    private const DAMAGE_NUMBER_PATTERNS = [
        '/Beschädigung\s*#?\s*(\d{1,3})\s*[:.]/iu',
        '/(?:^|\n)\s*(\d{1,3})\s+-\s+\S/u',
    ];

    private const GENERIC_WORDS = [
        'kratzer', 'delle', 'dellen', 'schaden', 'beschaedigt', 'deformiert', 'verschmutzt',
        'abrieb', 'riss', 'verkratzt', 'verschuerft', 'lackschaden', 'steinschlag', 'fehlt',
    ];

    private const MINIMUM_WORD_LENGTH = 4;

    private const MINIMUM_METHOD_LENGTH = 5;

    public function suggest(LeasybackOrder $order, AppraisalExtractionProposal $proposal): array
    {
        $candidates = $this->candidates($order);

        if ($candidates === []) {
            return [];
        }

        $suggestions = $this->byDamageNumber($proposal->lines, $candidates);
        $claimed = array_merge(...array_map(fn (array $match) => $match['document_ids'], array_values($suggestions))) ?: [];

        foreach ($proposal->lines as $index => $line) {
            if (isset($suggestions[$index])) {
                continue;
            }

            $match = $this->byText($line, $candidates, $claimed);

            if ($match !== null) {
                $suggestions[$index] = $match;
            }
        }

        ksort($suggestions);

        return $suggestions;
    }

    private function byDamageNumber(array $lines, array $candidates): array
    {
        $suggestions = [];

        foreach ($lines as $index => $line) {
            if (! $line instanceof AppraisalProposalLine || $line->damageNumber === null) {
                continue;
            }

            $documentIds = [];

            foreach ($candidates as $candidate) {
                if (count($candidate['damage_numbers']) === 1 && $candidate['damage_numbers'][0] === $line->damageNumber) {
                    $documentIds[] = $candidate['id'];
                }
            }

            if ($documentIds !== []) {
                $suggestions[$index] = ['document_ids' => $documentIds, 'strategy' => self::STRATEGY_DAMAGE_NUMBER];
            }
        }

        return $suggestions;
    }

    private function byText(AppraisalProposalLine $line, array $candidates, array $claimed): ?array
    {
        $words = $this->keywords($line->component);

        if ($words === []) {
            return null;
        }

        $method = $this->normalize((string) $line->repairMethod);
        $matched = [];

        foreach ($candidates as $candidate) {
            if (in_array($candidate['id'], $claimed, true) || $candidate['text'] === '') {
                continue;
            }

            $containsComponent = ! array_filter($words, fn (string $word) => ! str_contains($candidate['text'], $word));
            $containsMethod = mb_strlen($method) < self::MINIMUM_METHOD_LENGTH || str_contains($candidate['text'], $method);

            if ($containsComponent && $containsMethod) {
                $matched[] = $candidate['id'];
            }
        }

        return count($matched) === 1 ? ['document_ids' => $matched, 'strategy' => self::STRATEGY_TEXT] : null;
    }

    private function candidates(LeasybackOrder $order): array
    {
        $rows = DB::table('vehicle_report_documents as d')
            ->leftJoin('assessment_documents as a', 'a.id', '=', 'd.source_assessment_document_id')
            ->where('d.auftragsnummer', $order->auftragsnummer)
            ->where('d.vehicle_id', $order->vehicle_id)
            ->orderBy('a.sort_order')
            ->orderBy('d.created_at')
            ->get([
                'd.id', 'd.path', 'd.document_title',
                'a.caption', 'a.image_kind', 'a.title as assessment_title', 'a.doc_type',
            ]);

        $candidates = [];

        foreach ($rows as $row) {
            if (! ReportDocumentImage::isImage((string) $row->path)) {
                continue;
            }

            $text = trim(implode(' ', array_filter([$row->caption, $row->image_kind, $row->assessment_title, $row->document_title])));

            $candidates[] = [
                'id' => $row->id,
                'damage_numbers' => $this->damageNumbers($text),
                'text' => $this->normalize($text),
            ];
        }

        return $candidates;
    }

    private function damageNumbers(string $text): array
    {
        $numbers = [];

        foreach (self::DAMAGE_NUMBER_PATTERNS as $pattern) {
            if (preg_match_all($pattern, $text, $matches) > 0) {
                $numbers = array_merge($numbers, array_map('intval', $matches[1]));
            }
        }

        return array_values(array_unique($numbers));
    }

    private function keywords(string $component): array
    {
        $words = array_filter(
            explode(' ', $this->normalize($component)),
            fn (string $word) => mb_strlen($word) >= self::MINIMUM_WORD_LENGTH && ! in_array($word, self::GENERIC_WORDS, true),
        );

        return array_values(array_unique($words));
    }

    private function normalize(string $value): string
    {
        $lowered = mb_strtolower($value, 'UTF-8');
        $replaced = strtr($lowered, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $ascii = preg_replace('/[^a-z0-9]+/u', ' ', $replaced) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $ascii) ?? '');
    }
}
