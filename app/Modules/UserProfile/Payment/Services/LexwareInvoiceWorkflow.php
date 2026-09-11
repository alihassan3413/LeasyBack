<?php

namespace App\Modules\UserProfile\Payment\Services;

use App\Enums\DocumentType;
use App\Enums\OrderStatus;
use App\Modules\UserProfile\Admin\Services\VehicleReportService;
use App\Modules\UserProfile\Offer\Models\LeasybackOffer;
use App\Modules\UserProfile\Order\Models\B2bOfferPresentation;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\OrderStatusUpdate;
use App\Modules\UserProfile\Payment\Contracts\LexwareGateway;
use App\Modules\UserProfile\Payment\Data\LexwareInvoiceLine;
use App\Modules\UserProfile\Payment\Data\LexwareInvoiceRequest;
use App\Modules\UserProfile\Payment\Enums\LexwareInvoiceStatus;
use App\Modules\UserProfile\Payment\Exceptions\LexwareGatewayException;
use App\Modules\UserProfile\Payment\Models\LexwareInvoice;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Support\OfferPricingPolicy;
use App\Support\PortalTimestamp;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class LexwareInvoiceWorkflow
{
    private const BILLABLE_STATUSES = [
        OrderStatus::Delivered->value,
        OrderStatus::Completed->value,
    ];

    public function __construct(
        private readonly LexwareGateway $lexware,
        private readonly LexwareContactResolver $contacts,
        private readonly VehicleReportService $documents,
    ) {}

    public function issueRepairInvoice(LeasybackOrder $order, bool $isB2b): ?LexwareInvoice
    {
        if ($isB2b || ! in_array($order->order_status, self::BILLABLE_STATUSES, true)) {
            return null;
        }

        $record = $this->claim($order);

        if ($record->status->blocksAutomation()) {
            return $record;
        }

        if ($record->status->isSettled() && $this->documentIsHealthy($record)) {
            return $record;
        }

        if (! $record->hasInvoice()) {
            $this->createInvoice($record, $order);
            $record = $record->fresh();
        }

        if ($record->status === LexwareInvoiceStatus::Pending) {
            $this->captureInvoice($record);
            $record = $record->fresh();
        }

        if (! $record->hasDocument() || ! $this->documentIsHealthy($record)) {
            $this->storeDocument($record, $order);
            $record = $record->fresh();
        }

        return $record;
    }

    private function documentIsHealthy(LexwareInvoice $record): bool
    {
        $document = $record->document;

        return $document !== null && $this->documents->fileExists($document->path);
    }

    private function claim(LeasybackOrder $order): LexwareInvoice
    {
        try {
            return LexwareInvoice::create([
                'order_id' => $order->id,
                'auftragsnummer' => $order->auftragsnummer,
                'purpose' => LexwareInvoice::PURPOSE_REPAIR,
                'status' => LexwareInvoiceStatus::Pending,
            ]);
        } catch (QueryException) {
            return LexwareInvoice::where('order_id', $order->id)
                ->where('purpose', LexwareInvoice::PURPOSE_REPAIR)
                ->firstOrFail();
        }
    }

    private function createInvoice(LexwareInvoice $record, LeasybackOrder $order): void
    {
        try {
            $request = $this->invoiceRequest($order);
        } catch (LexwareGatewayException $exception) {
            $record->update(['failure_reason' => $exception->getMessage()]);

            throw $exception;
        }

        if (! $this->claimSubmission($record)) {
            throw LexwareGatewayException::apiError(
                'A previous Lexware invoice submission for this order is unresolved — refusing to send a second one.',
            );
        }

        try {
            $created = $this->lexware->createFinalizedInvoice($request);
        } catch (LexwareGatewayException $exception) {
            if ($exception->isTransportFailure()) {
                $record->update([
                    'status' => LexwareInvoiceStatus::NeedsReconciliation,
                    'failure_reason' => $exception->getMessage(),
                ]);

                throw $exception;
            }

            $record->update(['submitted_at' => null, 'failure_reason' => $exception->getMessage()]);

            throw $exception;
        }

        $record->update([
            'lexware_invoice_id' => $created->id,
            'resource_uri' => $created->resourceUri,
            'lexware_version' => $created->version,
            'voucher_status' => $created->voucherStatus,
            'voucher_number' => $created->voucherNumber,
            'failure_reason' => null,
        ]);
    }

    private function captureInvoice(LexwareInvoice $record): void
    {
        $invoice = $this->lexware->retrieveInvoice((string) $record->lexware_invoice_id);

        if (! $invoice->isFinalized()) {
            $record->update([
                'status' => LexwareInvoiceStatus::NeedsReconciliation,
                'voucher_status' => $invoice->voucherStatus,
                'failure_reason' => 'Lexware kept the invoice as a draft instead of finalizing it.',
            ]);

            throw LexwareGatewayException::apiError('The Lexware invoice was not finalized.');
        }

        $record->update([
            'voucher_number' => $invoice->voucherNumber,
            'voucher_status' => $invoice->voucherStatus,
            'lexware_version' => $invoice->version ?? $record->lexware_version,
            'resource_uri' => $invoice->resourceUri ?? $record->resource_uri,
            'status' => LexwareInvoiceStatus::Invoiced,
            'invoiced_at' => now(),
            'failure_reason' => null,
        ]);
    }

    private function storeDocument(LexwareInvoice $record, LeasybackOrder $order): void
    {
        $file = $this->lexware->downloadInvoiceFile((string) $record->lexware_invoice_id);

        $reference = trim((string) ($record->voucher_number ?? $record->lexware_invoice_id));

        $document = $this->documents->storeGeneratedDocument(
            auftragsnummer: (string) $order->auftragsnummer,
            vehicleId: (string) $order->vehicle_id,
            filename: sprintf('Rechnung-%s.pdf', $reference),
            contents: $file->contents,
            documentType: DocumentType::Rechnung->value,
            documentTitle: trim(sprintf('Rechnung %s', $record->voucher_number ?? '')),
            notifyCustomer: false,
        );

        $record->update([
            'document_id' => $document->id,
            'status' => LexwareInvoiceStatus::Documented,
            'documented_at' => now(),
            'failure_reason' => null,
        ]);
    }

    private function claimSubmission(LexwareInvoice $record): bool
    {
        return DB::table('lexware_invoices')
            ->where('id', $record->id)
            ->whereNull('submitted_at')
            ->update(['submitted_at' => now(), 'updated_at' => now()]) === 1;
    }

    private function invoiceRequest(LeasybackOrder $order): LexwareInvoiceRequest
    {
        $offer = $this->acceptedOffer($order);
        $lines = $this->lines($offer);

        if ($lines === []) {
            throw LexwareGatewayException::apiError('The accepted offer has no billable repair position.');
        }

        $vehicle = Vehicle::where('vehicle_id', $order->vehicle_id)->first();
        $performanceDate = $this->performanceDate($order);

        return new LexwareInvoiceRequest(
            contactId: $this->contacts->forOrder($order),
            lineItems: $lines,
            voucherDate: PortalTimestamp::now()->toDateString(),
            performanceDate: $performanceDate,
            introduction: sprintf('LeasyBack Auftrag %s', $order->auftragsnummer),
            remark: $this->vehicleReference($vehicle),
        );
    }

    private function acceptedOffer(LeasybackOrder $order): LeasybackOffer
    {
        $offer = LeasybackOffer::where('order_id', $order->id)
            ->whereIn('offer_status', ['selected', 'closed'])
            ->orderByDesc('selected_at')
            ->first();

        if ($offer === null) {
            throw LexwareGatewayException::apiError('The order has no accepted offer to invoice.');
        }

        return $offer;
    }

    /**
     * @return list<LexwareInvoiceLine>
     */
    private function lines(LeasybackOffer $offer): array
    {
        $presentation = B2bOfferPresentation::where('offer_id', $offer->offer_id)->first();

        return $presentation !== null
            ? $this->presentationLines($presentation)
            : $this->manualOfferLines($offer);
    }

    /**
     * @return list<LexwareInvoiceLine>
     */
    private function presentationLines(B2bOfferPresentation $presentation): array
    {
        if ($presentation->vat_rate === null) {
            throw LexwareGatewayException::apiError('The accepted offer carries no stamped VAT rate.');
        }

        $taxRate = $this->taxRatePercentage((string) $presentation->vat_rate);
        $lines = [];

        foreach ((array) ($presentation->lines ?? []) as $line) {
            $line = (array) $line;
            $amount = $line['repair_amount_net'] ?? null;

            if ($amount === null || (bool) ($line['not_repairable'] ?? false)) {
                continue;
            }

            $lines[] = new LexwareInvoiceLine(
                name: (string) ($line['component'] ?? 'Reparatur'),
                netAmountCents: (int) bcmul((string) $amount, '100', 0),
                taxRatePercentage: $taxRate,
                description: (string) ($line['repair_method'] ?? $line['damage_description'] ?? ''),
            );
        }

        return $lines;
    }

    /**
     * @return list<LexwareInvoiceLine>
     */
    private function manualOfferLines(LeasybackOffer $offer): array
    {
        $vatRate = $offer->vat_rate !== null ? (string) $offer->vat_rate : OfferPricingPolicy::vatRate();
        $net = (string) $offer->final_total_net;

        if (bccomp($net, '0', 2) <= 0) {
            return [];
        }

        return [new LexwareInvoiceLine(
            name: 'Reparatur',
            netAmountCents: (int) bcmul($net, '100', 0),
            taxRatePercentage: $this->taxRatePercentage($vatRate),
            description: '',
        )];
    }

    private function taxRatePercentage(string $rate): int
    {
        $percentage = bcmul($rate, '100', 4);

        if (bccomp($percentage, bcadd($percentage, '0', 0), 4) !== 0) {
            throw LexwareGatewayException::apiError(
                sprintf('The stamped VAT rate %s is not a whole percentage.', $rate),
            );
        }

        return (int) bcadd($percentage, '0', 0);
    }

    private function performanceDate(LeasybackOrder $order): string
    {
        $deliveredAt = OrderStatusUpdate::where('auftragsnummer', $order->auftragsnummer)
            ->where('new_status', OrderStatus::Delivered->value)
            ->orderByDesc('created_at')
            ->value('created_at');

        return PortalTimestamp::instant($deliveredAt)?->toDateString()
            ?? PortalTimestamp::now()->toDateString();
    }

    private function vehicleReference(?Vehicle $vehicle): string
    {
        if ($vehicle === null) {
            return '';
        }

        $reference = array_filter([$vehicle->license_plate, $vehicle->make, $vehicle->model]);
        $vin = trim((string) $vehicle->vin);

        if ($vin !== '') {
            $reference[] = 'VIN: '.$vin;
        }

        return implode(' · ', $reference);
    }
}
