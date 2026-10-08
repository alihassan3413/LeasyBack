<?php

namespace App\Modules\UserProfile\Order\Services;

use App\Enums\DocumentType;
use App\Models\LeasybackOffer;
use App\Models\User;
use App\Modules\UserProfile\Admin\Services\VehicleReportService;
use App\Modules\UserProfile\B2B\Services\B2bServiceFeeService;
use App\Modules\UserProfile\Order\Actions\TransitionOrderStatus;
use App\Modules\UserProfile\Order\Models\B2bOfferPresentation;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\OrderAuditLog;
use App\Modules\UserProfile\Payment\Contracts\LexwareGateway;
use App\Modules\UserProfile\Payment\Data\LexwareContactRequest;
use App\Modules\UserProfile\Payment\Data\LexwareInvoiceLine;
use App\Modules\UserProfile\Payment\Data\LexwareInvoiceRequest;
use App\Modules\UserProfile\Payment\Enums\LexwareInvoiceStatus;
use App\Modules\UserProfile\Payment\Exceptions\LexwareGatewayException;
use App\Modules\UserProfile\Payment\Models\LexwareContact;
use App\Modules\UserProfile\Payment\Models\LexwareInvoice;
use App\Support\OfferPricingPolicy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The B2B invoice in Lexware (b2b.txt §13).
 *
 * Created finalized, exactly as the B2C repair invoice is
 * (LexwareInvoiceWorkflow): Lexware assigns the invoice number at once, and the
 * PDF is fetched and filed with the order's documents in the same request —
 * unpublished, so the company only sees it once Admin publishes it. It carries
 * the accepted repair positions from the frozen offer snapshot, the vehicle
 * and order references, the company's service fee as it applied on the
 * billing date — never shown in the repair offer itself — and any extra
 * positions (transport, additional services) the admin adds before creating it.
 *
 * A finalized Lexware invoice cannot be deleted, only cancelled with a credit
 * note, so it is created at most once per order: the row is claimed before
 * Lexware is called, and a request whose outcome is unknown is never retried.
 *
 * Invoices created as drafts before this change still exist; finalize() is
 * how they — and any invoice whose PDF could not be fetched at once — are
 * completed. Class and route keep their "draft" names for that reason.
 */
class B2bLexwareDraftService
{
    public const PURPOSE = 'b2b_billing';

    public function __construct(
        private readonly B2bServiceFeeService $serviceFees,
        private readonly VehicleReportService $documents,
    ) {}

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'additional_positions' => ['nullable', 'array', 'max:20'],
            'additional_positions.*.name' => ['required', 'string', 'max:255'],
            'additional_positions.*.amount_net' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:99999999.99'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function create(LeasybackOrder $order, User $user, array $validated): LexwareInvoice
    {
        $order = $order->fresh() ?? $order;

        if (! TransitionOrderStatus::isB2bOrder($order)) {
            $this->refuse('Lexware-Rechnungen werden hier nur für B2B-Aufträge erstellt.');
        }

        if (! in_array($order->order_status, B2bBillingService::EDITABLE_STATUSES, true)) {
            $this->refuse('Die Rechnung kann erst nach der Rückgabe an den Leasinggeber erstellt werden.');
        }

        $this->refuseIfAlreadyCreated(LexwareInvoice::where('order_id', $order->id)->where('purpose', self::PURPOSE)->first());

        $company = DB::table('vehicles as v')
            ->join('b2b as b', 'b.b2b_id', '=', 'v.b2b_id')
            ->leftJoin('addresses as a', 'a.address_id', '=', 'b.address_id')
            ->where('v.vehicle_id', $order->vehicle_id)
            ->first(['b.b2b_id', 'b.contact_id', 'b.company_name', 'a.street', 'a.number', 'a.zip_code', 'a.city', 'a.country',
                'v.license_plate', 'v.vin', 'v.make', 'v.model']);

        if ($company === null) {
            $this->refuse('Zum Fahrzeug dieses Auftrags ist kein Unternehmen hinterlegt.');
        }

        // Everything that can be refused is decided before the row is claimed,
        // so a refusal never leaves a claim behind.
        $taxRate = $this->taxRatePercentage();
        $lines = [
            ...$this->repairLines($order, $taxRate),
            $this->serviceFeeLine($company->b2b_id, $taxRate),
            ...$this->additionalLines($validated['additional_positions'] ?? [], $taxRate),
        ];

        try {
            // Resolving the client is what refuses a disabled integration.
            $gateway = app(LexwareGateway::class);
            $request = new LexwareInvoiceRequest(
                contactId: $this->contactId($gateway, $company),
                lineItems: $lines,
                voucherDate: now()->toDateString(),
                performanceDate: now()->toDateString(),
                introduction: $this->introduction($order, $company),
                remark: 'Erstellt aus dem LeasyBack-Portal.',
            );
        } catch (LexwareGatewayException $e) {
            $this->refuseGatewayFailure($e, $order, 'B2B Lexware contact failed', 'Die Rechnung konnte nicht an Lexware übertragen werden: ');
        }

        $invoice = $this->claim($order);

        try {
            $result = $gateway->createFinalizedInvoice($request);
        } catch (LexwareGatewayException $e) {
            if ($e->isTransportFailure()) {
                // Lexware may have created it and the answer got lost. A finalized
                // invoice cannot be undone, so this is never retried blindly.
                $invoice->update(['status' => LexwareInvoiceStatus::NeedsReconciliation, 'failure_reason' => $e->getMessage()]);
                Log::warning('B2B Lexware invoice outcome unknown', ['order_id' => $order->id, 'error' => $e->getMessage()]);
                $this->refuse('Lexware hat nicht geantwortet — ob die Rechnung angelegt wurde, ist unklar. Bitte prüfen Sie sie in Lexware und laden Sie sie gegebenenfalls manuell hoch.');
            }

            // Lexware refused it outright: nothing exists there, so the claim is released for a retry.
            $invoice->update(['submitted_at' => null, 'failure_reason' => $e->getMessage()]);
            $this->refuseGatewayFailure($e, $order, 'B2B Lexware invoice failed', 'Die Rechnung konnte nicht in Lexware erstellt werden: ');
        }

        $invoice = DB::transaction(function () use ($invoice, $order, $user, $result, $lines) {
            $invoice->update([
                'status' => LexwareInvoiceStatus::Invoiced,
                'lexware_invoice_id' => $result->id,
                'resource_uri' => $result->resourceUri,
                'lexware_version' => $result->version,
                'voucher_status' => $result->voucherStatus,
                'voucher_number' => $result->voucherNumber,
                'invoiced_at' => now(),
                'failure_reason' => null,
            ]);

            OrderAuditLog::create([
                'order_id' => $order->id,
                'vehicle_id' => $order->vehicle_id,
                'action' => 'LEXWARE_INVOICE_CREATED',
                'old_values' => null,
                'new_values' => [
                    'lexware_invoice_id' => $result->id,
                    'voucher_number' => $result->voucherNumber,
                    'positions' => array_map(fn (LexwareInvoiceLine $line) => [
                        'name' => $line->name,
                        'net_amount_cents' => $line->netAmountCents,
                    ], $lines),
                ],
                'changed_by_user_id' => $user->id,
            ]);

            return $invoice->fresh() ?? $invoice;
        });

        // The PDF, at once. If Lexware cannot hand it over yet the invoice
        // stands regardless — finalize() fetches it on the next attempt.
        try {
            return $this->fileDocument($invoice, $order, $user);
        } catch (ValidationException) {
            return $invoice->fresh() ?? $invoice;
        }
    }

    /**
     * Fetches the PDF of an invoice that exists but has no document yet: one
     * created before invoices were finalized at once (accounting finalizes the
     * draft in Lexware first), or one whose PDF could not be fetched when it
     * was created.
     *
     * The PDF is filed with the order's other documents but left unpublished:
     * admin sees it at once, the company only once somebody publishes it.
     */
    public function finalize(LeasybackOrder $order, User $user): LexwareInvoice
    {
        $invoice = LexwareInvoice::where('order_id', $order->id)->where('purpose', self::PURPOSE)->first();

        if ($invoice === null || $invoice->lexware_invoice_id === null) {
            $this->refuse('Für diesen Auftrag wurde noch keine Lexware-Rechnung erstellt.');
        }

        if ($invoice->document_id !== null) {
            $this->refuse('Die Rechnung wurde für diesen Auftrag bereits abgerufen.');
        }

        return $this->fileDocument($invoice, $order, $user);
    }

    private function fileDocument(LexwareInvoice $invoice, LeasybackOrder $order, User $user): LexwareInvoice
    {
        try {
            $gateway = app(LexwareGateway::class);
            $result = $gateway->requireFinalizedInvoice((string) $invoice->lexware_invoice_id);
            $file = $gateway->downloadInvoiceFile($result->id);
        } catch (LexwareGatewayException $e) {
            // Only an invoice created as a draft (before invoices were finalized
            // at once) can still be one, and only Lexware's own UI finalizes it.
            if ($e->isNotFinalized()) {
                $this->refuse(
                    'Der Entwurf ist in Lexware noch nicht finalisiert. Bitte finalisieren Sie ihn dort — '
                    .'erst dann erhält die Rechnung ihre Nummer und ihr PDF — und rufen Sie sie anschließend hier ab.'
                );
            }

            // Lexware's own error body names the cause; without it the log says
            // only "HTTP 404" and diagnoses nothing.
            Log::warning('B2B Lexware document failed', [
                'order_id' => $order->id,
                'lexware_invoice_id' => $invoice->lexware_invoice_id,
                'error' => $e->getMessage(),
                'http_status' => $e->httpStatus,
                'lexware_error' => $e->errorBody,
            ]);

            // Reported, never swallowed: an invoice that silently fails to
            // arrive is the whole problem this step exists to end.
            $this->refuse(str_contains($e->getMessage(), 'LEXWARE_INTEGRATION_MODE')
                ? 'Die Lexware-Integration ist nicht aktiviert. Bitte erfassen Sie die Rechnung manuell.'
                : 'Das Rechnungs-PDF konnte nicht aus Lexware abgerufen werden: '.$e->getMessage());
        }

        $reference = $result->voucherNumber ?? $result->id;

        $document = $this->documents->storeGeneratedDocument(
            auftragsnummer: (string) $order->auftragsnummer,
            vehicleId: (string) $order->vehicle_id,
            filename: sprintf('Rechnung-%s.pdf', $reference),
            contents: $file->contents,
            documentType: DocumentType::Rechnung->value,
            documentTitle: trim(sprintf('Rechnung %s', $result->voucherNumber ?? '')),
            notifyCustomer: false,
            published: false,
        );

        return DB::transaction(function () use ($invoice, $order, $user, $result, $document) {
            $invoice->update([
                'status' => LexwareInvoiceStatus::Documented,
                'voucher_number' => $result->voucherNumber ?? $invoice->voucher_number,
                'voucher_status' => $result->voucherStatus ?? $invoice->voucher_status,
                'lexware_version' => $result->version ?? $invoice->lexware_version,
                'document_id' => $document->id,
                'invoiced_at' => $invoice->invoiced_at ?? now(),
                'documented_at' => now(),
                'failure_reason' => null,
            ]);

            OrderAuditLog::create([
                'order_id' => $order->id,
                'vehicle_id' => $order->vehicle_id,
                'action' => 'LEXWARE_INVOICE_FINALIZED',
                'old_values' => null,
                'new_values' => [
                    'lexware_invoice_id' => $result->id,
                    'voucher_number' => $result->voucherNumber,
                    'document_id' => $document->id,
                ],
                'changed_by_user_id' => $user->id,
            ]);

            return $invoice->fresh() ?? $invoice;
        });
    }

    /**
     * Claims the order's one Lexware invoice before Lexware is called. The
     * unique (order_id, purpose) index stops a second row; the conditional
     * update on submitted_at stops two requests sharing a row left by an
     * earlier refused attempt.
     */
    private function claim(LeasybackOrder $order): LexwareInvoice
    {
        try {
            $invoice = LexwareInvoice::create([
                'order_id' => $order->id,
                'auftragsnummer' => $order->auftragsnummer,
                'purpose' => self::PURPOSE,
                'status' => LexwareInvoiceStatus::Pending,
            ]);
        } catch (QueryException) {
            $invoice = LexwareInvoice::where('order_id', $order->id)->where('purpose', self::PURPOSE)->firstOrFail();
            $this->refuseIfAlreadyCreated($invoice);
        }

        $claimed = DB::table('lexware_invoices')
            ->where('id', $invoice->id)
            ->whereNull('submitted_at')
            ->whereNull('lexware_invoice_id')
            ->update(['submitted_at' => now(), 'updated_at' => now()]);

        if ($claimed === 0) {
            $this->refuse('Die Rechnung wird für diesen Auftrag bereits erstellt.');
        }

        return $invoice->fresh() ?? $invoice;
    }

    private function refuseIfAlreadyCreated(?LexwareInvoice $invoice): void
    {
        if ($invoice === null) {
            return;
        }

        if ($invoice->lexware_invoice_id !== null) {
            $this->refuse('Für diesen Auftrag wurde bereits eine Lexware-Rechnung erstellt.');
        }

        if ($invoice->status === LexwareInvoiceStatus::NeedsReconciliation) {
            $this->refuse('Ob die Rechnung in Lexware angelegt wurde, ist unklar. Bitte prüfen Sie sie in Lexware und laden Sie sie gegebenenfalls manuell hoch.');
        }
    }

    private function refuseGatewayFailure(LexwareGatewayException $e, LeasybackOrder $order, string $logMessage, string $prefix): never
    {
        Log::warning($logMessage, [
            'order_id' => $order->id,
            'error' => $e->getMessage(),
            'http_status' => $e->httpStatus,
            'lexware_error' => $e->errorBody,
        ]);

        $this->refuse(str_contains($e->getMessage(), 'LEXWARE_INTEGRATION_MODE')
            ? 'Die Lexware-Integration ist nicht aktiviert. Bitte erfassen Sie die Rechnung manuell.'
            : $prefix.$e->getMessage());
    }

    /**
     * @return array{lexware_invoice_id: string|null, voucher_number: string|null, voucher_status: string|null, submitted_at: string|null, document_id: string|null, lexware_url: string|null}|null
     */
    public function summary(string $orderId): ?array
    {
        $invoice = LexwareInvoice::where('order_id', $orderId)->where('purpose', self::PURPOSE)->first();

        return $invoice === null ? null : [
            'lexware_invoice_id' => $invoice->lexware_invoice_id,
            'voucher_number' => $invoice->voucher_number,
            'voucher_status' => $invoice->voucher_status,
            'submitted_at' => $invoice->submitted_at?->toISOString(),
            'document_id' => $invoice->document_id,
            // The invoice in Lexware itself — and, for one created as a draft
            // before invoices were finalized at once, where it is finalized.
            'lexware_url' => $invoice->lexware_invoice_id === null
                ? null
                : config('services.lexware.app_url').'/permalink/invoices/view/'.$invoice->lexware_invoice_id,
        ];
    }

    /**
     * The positions the customer approved, at the workshop's net prices, from
     * the snapshot frozen when the offer was presented.
     *
     * @return list<LexwareInvoiceLine>
     */
    private function repairLines(LeasybackOrder $order, int $taxRate): array
    {
        $offer = LeasybackOffer::where('order_id', $order->id)->where('offer_status', 'selected')->first();

        if ($offer === null) {
            $this->refuse('Für diesen Auftrag liegt kein angenommenes Angebot vor, aus dem Rechnungspositionen erstellt werden können.');
        }

        $presentation = B2bOfferPresentation::where('offer_id', $offer->offer_id)->first();
        $lines = [];

        foreach ($presentation?->lines ?? [] as $line) {
            if (($line['repair_amount_net'] ?? null) === null) {
                continue;
            }

            $lines[] = new LexwareInvoiceLine(
                name: (string) $line['component'],
                netAmountCents: (int) bcmul((string) $line['repair_amount_net'], '100', 0),
                taxRatePercentage: $taxRate,
                description: trim((string) ($line['repair_method'] ?? '')),
            );
        }

        // A manually created offer has no presentation lines: it is billed as
        // one repair position at its accepted net total.
        if ($lines === [] && bccomp((string) $offer->final_total_net, '0', 2) > 0) {
            $lines[] = new LexwareInvoiceLine(
                name: 'Reparatur',
                netAmountCents: (int) bcmul((string) $offer->final_total_net, '100', 0),
                taxRatePercentage: $taxRate,
            );
        }

        return $lines;
    }

    private function serviceFeeLine(string $b2bId, int $taxRate): LexwareInvoiceLine
    {
        return new LexwareInvoiceLine(
            name: 'Servicepauschale',
            netAmountCents: (int) bcmul($this->serviceFees->amountOn($b2bId, now()), '100', 0),
            taxRatePercentage: $taxRate,
            description: 'Vereinbarte jährliche Servicepauschale',
        );
    }

    /**
     * @param  array<int, array{name: string, amount_net: string|int|float}>  $positions
     * @return list<LexwareInvoiceLine>
     */
    private function additionalLines(array $positions, int $taxRate): array
    {
        return array_values(array_map(fn (array $position) => new LexwareInvoiceLine(
            name: trim((string) $position['name']),
            netAmountCents: (int) bcmul((string) $position['amount_net'], '100', 0),
            taxRatePercentage: $taxRate,
        ), $positions));
    }

    private function introduction(LeasybackOrder $order, object $company): string
    {
        return implode("\n", array_filter([
            'Auftragsnummer: '.$order->auftragsnummer,
            $company->license_plate ? 'Kennzeichen: '.$company->license_plate : null,
            $company->vin ? 'FIN: '.$company->vin : null,
            trim(($company->make ?? '').' '.($company->model ?? '')) ?: null,
        ]));
    }

    private function contactId(LexwareGateway $gateway, object $company): string
    {
        $existing = LexwareContact::where('contact_id', $company->contact_id)->value('lexware_contact_id');

        if ($existing !== null) {
            return $existing;
        }

        $street = trim(($company->street ?? '').' '.($company->number ?? ''));

        if ($street === '' || trim((string) $company->zip_code) === '' || trim((string) $company->city) === '') {
            throw LexwareGatewayException::apiError('Für das Unternehmen ist keine vollständige Rechnungsadresse hinterlegt.');
        }

        $id = $gateway->createCompanyContact(new LexwareContactRequest(
            companyName: (string) $company->company_name,
            street: $street,
            zip: trim((string) $company->zip_code),
            city: trim((string) $company->city),
        ));

        LexwareContact::firstOrCreate(['contact_id' => $company->contact_id], ['lexware_contact_id' => $id]);

        return $id;
    }

    private function taxRatePercentage(): int
    {
        return (int) bcmul((string) OfferPricingPolicy::vatRate(), '100', 0);
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['lexware' => $message]);
    }
}
