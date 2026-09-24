<?php

namespace App\Modules\UserProfile\Order\Services\Extraction;

use App\Modules\UserProfile\Order\Data\AppraisalProposalLine;
use App\Modules\UserProfile\Order\Data\PdfPageText;

class GutachtenTotals
{
    public function detect(array $pages): ?string
    {
        $candidates = [];

        foreach ($pages as $page) {
            if (! $page instanceof PdfPageText) {
                continue;
            }

            foreach ($page->lines as $line) {
                $amounts = GutachtenAmount::matchAll($line);

                if ($amounts === []) {
                    continue;
                }

                foreach ((array) config('gutachten.totals.patterns') as $candidate) {
                    if (preg_match((string) $candidate['pattern'], $line) !== 1) {
                        continue;
                    }

                    $value = $candidate['amount'] === 'last' ? $amounts[count($amounts) - 1]['value'] : $amounts[0]['value'];

                    if (GutachtenAmount::isPositive($value)) {
                        $candidates[] = ['priority' => (int) $candidate['priority'], 'value' => $value];
                    }
                }
            }
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn (array $a, array $b) => $b['priority'] <=> $a['priority']);

        return $candidates[0]['value'];
    }

    public function sum(array $lines): string
    {
        $sum = '0.00';

        foreach ($lines as $line) {
            if ($line instanceof AppraisalProposalLine) {
                $sum = bcadd($sum, $line->chargeableAmountNet ?? $line->originalAmountNet, 2);
            }
        }

        return $sum;
    }

    public function warnings(?string $total, string $sum): array
    {
        if ($total === null) {
            return [$this->warning('appraisal_total_not_found', 'Im Gutachten wurde keine Gesamtsumme gefunden.')];
        }

        $difference = bcsub($total, $sum, 2);
        $tolerance = (string) config('gutachten.totals.tolerance');

        if (bccomp($this->absolute($difference), $tolerance, 2) !== 1) {
            return [];
        }

        return [bccomp($difference, '0.00', 2) === 1
            ? $this->warning('missing_positions', "Die erkannten Positionen liegen {$this->absolute($difference)} unter der Gesamtsumme {$total}.")
            : $this->warning('sum_exceeds_total', "Die erkannten Positionen liegen {$this->absolute($difference)} über der Gesamtsumme {$total}."),
        ];
    }

    private function absolute(string $value): string
    {
        return bccomp($value, '0.00', 2) === -1 ? bcmul($value, '-1', 2) : $value;
    }

    private function warning(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message, 'line' => null];
    }
}
