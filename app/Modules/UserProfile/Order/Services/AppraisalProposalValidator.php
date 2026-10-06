<?php

namespace App\Modules\UserProfile\Order\Services;

use App\Modules\UserProfile\Order\Data\AppraisalExtractionProposal;
use App\Modules\UserProfile\Order\Data\AppraisalProposalLine;
use App\Modules\UserProfile\Order\Exceptions\AppraisalExtractionException;
use Illuminate\Support\Facades\Validator;

final class AppraisalProposalValidator
{
    public const LOW_CONFIDENCE_THRESHOLD = 0.6;

    private const AMOUNT_PATTERN = '/^\d{1,8}(\.\d{1,2})?$/';

    public function validate(AppraisalExtractionProposal $proposal, ?string $vehicleVin = null): array
    {
        $validator = Validator::make(
            ['positions' => array_map(fn (AppraisalProposalLine $line) => $this->asPosition($line), $proposal->lines)],
            AppraisalPositionService::rules([]),
        );

        $errors = $validator->errors()->toArray();

        foreach ($proposal->lines as $index => $line) {
            if (preg_match(self::AMOUNT_PATTERN, $line->originalAmountNet) !== 1) {
                $errors["positions.{$index}.original_amount_net"][] = 'The amount must be a plain decimal with at most two places.';
            }

            if ($line->chargeableAmountNet !== null && preg_match(self::AMOUNT_PATTERN, $line->chargeableAmountNet) !== 1) {
                $errors["positions.{$index}.chargeable_amount_net"][] = 'The amount must be a plain decimal with at most two places.';
            }
        }

        if ($proposal->totalNet !== null && preg_match(self::AMOUNT_PATTERN, $proposal->totalNet) !== 1) {
            $errors['total_net'][] = 'The total must be a plain decimal with at most two places.';
        }

        if ($errors !== []) {
            throw AppraisalExtractionException::invalidProposal();
        }

        return $this->warnings($proposal, $vehicleVin);
    }

    private function asPosition(AppraisalProposalLine $line): array
    {
        return [
            'component' => $line->component,
            'damage_description' => $line->damageDescription,
            'original_amount_net' => $line->originalAmountNet,
            'chargeable_amount_net' => $line->chargeableAmountNet,
            'repair_method' => $line->repairMethod,
        ];
    }

    private function warnings(AppraisalExtractionProposal $proposal, ?string $vehicleVin): array
    {
        $warnings = [];
        $seenComponents = [];
        $sum = '0';

        foreach ($proposal->lines as $index => $line) {
            $sum = bcadd($sum, $line->chargeableAmountNet ?? $line->originalAmountNet, 2);

            if ($line->chargeableAmountNet !== null && bccomp($line->chargeableAmountNet, $line->originalAmountNet, 2) === 1) {
                $warnings[] = $this->warning('chargeable_exceeds_original', 'Der berechenbare Betrag übersteigt den Gutachtenbetrag.', $index);
            }

            if ($line->confidence !== null && $line->confidence < self::LOW_CONFIDENCE_THRESHOLD) {
                $warnings[] = $this->warning('low_confidence', 'Diese Position wurde mit geringer Sicherheit erkannt.', $index);
            }

            $key = mb_strtolower(trim($line->component));

            if (isset($seenComponents[$key])) {
                $warnings[] = $this->warning('duplicate_component', 'Dieses Bauteil kommt mehrfach vor.', $index);
            }

            $seenComponents[$key] = true;
        }

        if ($proposal->totalNet !== null && bccomp($sum, $proposal->totalNet, 2) !== 0) {
            $warnings[] = $this->warning('total_mismatch', "Die erkannten Positionen ergeben in Summe {$sum}, das Gutachten weist {$proposal->totalNet} aus.");
        }

        if ($proposal->vin !== null && $vehicleVin !== null && $this->normalizeVin($proposal->vin) !== $this->normalizeVin($vehicleVin)) {
            $warnings[] = $this->warning('vin_mismatch', 'Die FIN im Gutachten weicht vom Fahrzeug ab.');
        }

        if ($proposal->currency !== null && strtoupper($proposal->currency) !== 'EUR') {
            $warnings[] = $this->warning('unexpected_currency', "Das Gutachten ist in {$proposal->currency} ausgewiesen, nicht in EUR.");
        }

        return $warnings;
    }

    private function warning(string $code, string $message, ?int $lineIndex = null): array
    {
        return ['code' => $code, 'message' => $message, 'line' => $lineIndex];
    }

    private function normalizeVin(string $vin): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $vin) ?? '');
    }
}
