<?php

namespace App\Services\Mail;

use App\Modules\UserProfile\Offer\Models\LeasybackOffer;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
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

    private function build(
        LeasybackOrder $order,
        ?Vehicle $vehicle,
        string $recipientName,
        string $actionUrl,
        ?LeasybackOffer $offer,
    ): OrderEmailData {
        $station = $this->stationPayload($order);

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
            offerTotalGross: $this->money($offer?->final_total_gross),
            actionUrl: $actionUrl,
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
