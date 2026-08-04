<?php

namespace App\Services\Mail;

class OrderEmailData
{
    public function __construct(
        public readonly string $recipientName,
        public readonly ?string $orderNumber = null,
        public readonly ?string $licensePlate = null,
        public readonly ?string $vin = null,
        public readonly ?string $make = null,
        public readonly ?string $model = null,
        public readonly ?string $statusValue = null,
        public readonly ?string $statusLabel = null,
        public readonly ?string $appointmentDate = null,
        public readonly ?string $stationName = null,
        public readonly ?string $stationAddress = null,
        public readonly ?string $provider = null,
        public readonly ?string $remarks = null,
        public readonly ?string $offerTotalGross = null,
        public readonly ?string $actionUrl = null,
    ) {}

    public function vehicleLabel(): ?string
    {
        $label = trim(($this->make ?? '').' '.($this->model ?? ''));

        return $label !== '' ? $label : null;
    }

    /**
     * @return array<string, string>
     */
    public function details(): array
    {
        $details = [
            'Auftragsnummer' => $this->orderNumber,
            'Kennzeichen' => $this->licensePlate,
            'Fahrzeug' => $this->vehicleLabel(),
            'FIN' => $this->vin,
            'Aktueller Status' => $this->statusLabel,
            'Termin' => $this->appointmentDate,
            'Prüfstation' => $this->stationName,
            'Adresse' => $this->stationAddress,
            'Dienstleister' => $this->provider,
            'Gesamtbetrag (brutto)' => $this->offerTotalGross,
            'Bemerkung' => $this->remarks,
        ];

        return array_filter(
            $details,
            static fn (?string $value): bool => $value !== null && trim($value) !== '',
        );
    }
}
