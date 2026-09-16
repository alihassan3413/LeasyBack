<?php

namespace App\Modules\UserProfile\Order\Services;

use App\Models\LeasybackOffer;
use App\Models\User;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The B2B invoice draft in Lexware (b2b.txt §13).
 *
 * A *draft*, deliberately: accounting reviews it in Lexware, where every
 * position stays editable and removable, and nothing here finalises it. It
 * carries the accepted repair positions from the frozen offer snapshot, the
 * vehicle and order references, the company's service fee as it applied on
 * the billing date — never shown in the repair offer itself — and any extra
 * positions (transport, additional services) the admin adds.
 *
 * Creating it satisfies the billing step's "invoice created or transmitted"
 * requirement; B2bBillingService accepts it as the invoice behind "processed".
 */
class B2bLexwareDraftService
{
    public const PURPOSE = 'b2b_billing';

    public function __construct(private readonly B2bServiceFeeService $serviceFees) {}

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
            $this->refuse('Lexware-Rechnungsentwürfe werden hier nur für B2B-Aufträge erstellt.');
        }

        if (! in_array($order->order_status, B2bBillingService::EDITABLE_STATUSES, true)) {
            $this->refuse('Der Rechnungsentwurf kann erst nach der Rückgabe an den Leasinggeber erstellt werden.');
        }

        if (LexwareInvoice::where('order_id', $order->id)->where('purpose', self::PURPOSE)->exists()) {
            $this->refuse('Für diesen Auftrag wurde bereits ein Lexware-Rechnungsentwurf erstellt.');
        }

        $company = DB::table('vehicles as v')
            ->join('b2b as b', 'b.b2b_id', '=', 'v.b2b_id')
            ->leftJoin('addresses as a', 'a.address_id', '=', 'b.address_id')
            ->where('v.vehicle_id', $order->vehicle_id)
            ->first(['b.b2b_id', 'b.contact_id', 'b.company_name', 'a.street', 'a.number', 'a.zip_code', 'a.city', 'a.country',
                'v.license_plate', 'v.vin', 'v.make', 'v.model']);

        if ($company === null) {
            $this->refuse('Zum Fahrzeug dieses Auftrags ist kein Unternehmen hinterlegt.');
        }

        $taxRate = $this->taxRatePercentage();
        $lines = [
            ...$this->repairLines($order, $taxRate),
            $this->serviceFeeLine($company->b2b_id, $taxRate),
            ...$this->additionalLines($validated['additional_positions'] ?? [], $taxRate),
        ];

        try {
            $gateway = app(LexwareGateway::class);
            $contactId = $this->contactId($gateway, $company);
            $result = $gateway->createInvoice(new LexwareInvoiceRequest(
                contactId: $contactId,
                lineItems: $lines,
                voucherDate: now()->toDateString(),
                performanceDate: now()->toDateString(),
                introduction: $this->introduction($order, $company),
                remark: 'Entwurf aus dem LeasyBack-Portal. Bitte vor dem Versand prüfen.',
            ));
        } catch (LexwareGatewayException $e) {
            Log::warning('B2B Lexware draft failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);

            $this->refuse(str_contains($e->getMessage(), 'LEXWARE_INTEGRATION_MODE')
                ? 'Die Lexware-Integration ist nicht aktiviert. Bitte erfassen Sie die Rechnung manuell.'
                : 'Der Rechnungsentwurf konnte nicht an Lexware übertragen werden: '.$e->getMessage());
        }

        return DB::transaction(function () use ($order, $user, $result, $lines) {
            $invoice = LexwareInvoice::create([
                'order_id' => $order->id,
                'auftragsnummer' => $order->auftragsnummer,
                'purpose' => self::PURPOSE,
                'status' => LexwareInvoiceStatus::Pending,
                'lexware_invoice_id' => $result->id,
                'resource_uri' => $result->resourceUri,
                'lexware_version' => $result->version,
                'voucher_status' => $result->voucherStatus ?? 'draft',
                'voucher_number' => $result->voucherNumber,
                'submitted_at' => now(),
            ]);

            OrderAuditLog::create([
                'order_id' => $order->id,
                'vehicle_id' => $order->vehicle_id,
                'action' => 'LEXWARE_DRAFT_CREATED',
                'old_values' => null,
                'new_values' => [
                    'lexware_invoice_id' => $result->id,
                    'positions' => array_map(fn (LexwareInvoiceLine $line) => [
                        'name' => $line->name,
                        'net_amount_cents' => $line->netAmountCents,
                    ], $lines),
                ],
                'changed_by_user_id' => $user->id,
            ]);

            return $invoice;
        });
    }

    /**
     * @return array{lexware_invoice_id: string|null, voucher_status: string|null, submitted_at: string|null}|null
     */
    public function summary(string $orderId): ?array
    {
        $invoice = LexwareInvoice::where('order_id', $orderId)->where('purpose', self::PURPOSE)->first();

        return $invoice === null ? null : [
            'lexware_invoice_id' => $invoice->lexware_invoice_id,
            'voucher_status' => $invoice->voucher_status,
            'submitted_at' => $invoice->submitted_at?->toISOString(),
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
