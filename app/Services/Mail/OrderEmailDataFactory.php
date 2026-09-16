<?php

namespace App\Services\Mail;

use App\Modules\UserProfile\Offer\Models\LeasybackOffer;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\LogisticsAddressProfile;
use App\Modules\UserProfile\Order\Models\OrderLogistics;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Support\OrderStatusLabel;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class OrderEmailDataFactory
{
    /**
     * @var array<string, string>
     */
    private const PROVIDER_LABELS = [
        'tuvsud' => 'TÜV SÜD',
        'dekra' => 'DEKRA',
    ];

    public function __construct(private readonly EmailUrlBuilder $urls) {}

    public function forCustomer(
        LeasybackOrder $order,
        ?Vehicle $vehicle,
        string $recipientName,
        ?LeasybackOffer $offer = null,
    ): OrderEmailData {
        return $this->build(
            $order,
            $vehicle,
            $recipientName,
            $this->urls->customerVehicleUrl($vehicle),
            $offer,
        );
    }

    public function forAdmin(
        LeasybackOrder $order,
        ?Vehicle $vehicle,
        ?LeasybackOffer $offer = null,
    ): OrderEmailData {
        return $this->build(
            $order,
            $vehicle,
            (string) config('mail.from.name'),
            $this->urls->adminOrderUrl($order->id),
            $offer,
        );
    }

    public function forRepairInvoice(
        LeasybackOrder $order,
        ?Vehicle $vehicle,
        string $recipientName,
        string $paymentUrl,
        ?string $invoiceNumber,
        ?LeasybackOffer $offer = null,
    ): OrderEmailData {
        return $this->build(
            $order,
            $vehicle,
            $recipientName,
            $paymentUrl,
            $offer,
            $invoiceNumber,
            $this->urls->customerVehicleUrl($vehicle),
        );
    }

    private function build(
        LeasybackOrder $order,
        ?Vehicle $vehicle,
        string $recipientName,
        string $actionUrl,
        ?LeasybackOffer $offer,
        ?string $invoiceNumber = null,
        ?string $documentUrl = null,
    ): OrderEmailData {
        $station = $this->stationPayload($order);
        $isB2b = $vehicle?->vehicle_belongs === 'B2B';
        $logistics = $isB2b ? OrderLogistics::where('auftragsnummer', $order->auftragsnummer)->first() : null;

        // A B2B offer is net-only (§9/§21). Its gross columns are placeholders
        // and must never be presented as a gross price.
        $netOnly = $offer !== null && $isB2b;

        return new OrderEmailData(
            recipientName: trim($recipientName) !== '' ? trim($recipientName) : 'Kunde',
            orderNumber: $order->auftragsnummer,
            licensePlate: $vehicle?->license_plate,
            vin: $vehicle?->vin,
            make: $vehicle?->make,
            model: $vehicle?->model,
            statusValue: $order->order_status,
            statusLabel: OrderStatusLabel::for($order->order_status),
            appointmentDate: $this->appointmentDate($order, $station),
            stationName: $this->stringOrNull($station['name'] ?? null),
            stationAddress: $this->stationAddress($station),
            provider: $this->providerLabel($order->leasyback_partner),
            remarks: $this->stringOrNull($order->request_payload['auftrag']['bemerkung'] ?? null),
            offerTotalGross: $netOnly ? null : $this->money($offer?->final_total_gross),
            actionUrl: $actionUrl,
            invoiceNumber: $invoiceNumber,
            documentUrl: $documentUrl,
            offerTotalNet: $netOnly ? $this->money($offer->final_total_net) : null,
            requestedCollectionDate: $this->plainDate($logistics?->requested_collection_date),
            confirmedCollectionDate: $this->plainDate($logistics?->confirmed_collection_date),
            collectionAddress: $this->collectionAddress($logistics),
            repairStartDate: in_array($order->order_status, ['workshop_commissioned', 'workshop'], true)
                ? $this->plainDate($logistics?->confirmed_repair_start_date)
                : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function stationPayload(LeasybackOrder $order): array
    {
        $station = $order->request_payload['besichtigungsort'] ?? [];

        return is_array($station) ? $station : [];
    }

    /**
     * @param  array<string, mixed>  $station
     */
    private function appointmentDate(LeasybackOrder $order, array $station): ?string
    {
        $confirmedAt = $order->relationLoaded('confirmation')
            ? $order->getRelation('confirmation')?->confirmation_date
            : $order->confirmation?->confirmation_date;

        if ($confirmedAt instanceof CarbonInterface) {
            return $this->formatDate($confirmedAt);
        }

        $termin = $this->stringOrNull($station['termin'] ?? null);

        if ($termin === null) {
            return null;
        }

        try {
            return $this->formatDate(Carbon::parse($termin));
        } catch (\Throwable) {
            return $termin;
        }
    }

    /**
     * @param  array<string, mixed>  $station
     */
    private function stationAddress(array $station): ?string
    {
        $street = trim((string) ($station['strasse'] ?? ''));
        $city = trim(trim((string) ($station['plz'] ?? '')).' '.trim((string) ($station['ort'] ?? '')));

        $address = trim(implode(', ', array_filter([$street, $city])), ', ');

        return $address !== '' ? $address : null;
    }

    private function providerLabel(?string $provider): ?string
    {
        if ($provider === null || trim($provider) === '') {
            return null;
        }

        $key = strtolower(trim($provider));

        return self::PROVIDER_LABELS[$key] ?? ucfirst($key);
    }

    private function money(mixed $amount): ?string
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        return number_format((float) $amount, 2, ',', '.').' €';
    }

    /**
     * Collection and repair dates are plain calendar days — no time and no
     * timezone conversion, or a date near midnight would move by a day.
     */
    private function plainDate(mixed $date): ?string
    {
        return $date instanceof CarbonInterface ? $date->format('d.m.Y') : null;
    }

    private function collectionAddress(?OrderLogistics $logistics): ?string
    {
        if ($logistics === null) {
            return null;
        }

        $address = $logistics->pickup_details;

        if ($address === null && $logistics->pickup_profile_id !== null) {
            $address = LogisticsAddressProfile::where('id', $logistics->pickup_profile_id)->value('details');
        }

        if (! is_array($address)) {
            return null;
        }

        $street = trim(($address['street'] ?? '').' '.($address['number'] ?? ''));
        $city = trim(($address['zip_code'] ?? '').' '.($address['city'] ?? ''));
        $line = trim(implode(', ', array_filter([$street, $city])), ', ');

        return $line !== '' ? $line : null;
    }

    private function formatDate(CarbonInterface $date): string
    {
        return $date->copy()->setTimezone('Europe/Berlin')->format('d.m.Y, H:i').' Uhr';
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
