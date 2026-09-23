@extends('emails.layout')

@section('title', 'Angebotsanfrage '.$orderReference)
@section('preheader', 'Wir bitten um ein Reparaturangebot für '.($licensePlate ?? $vehicleLabel))

@section('content')
    <div style="display:inline-block;margin:0 0 16px 0;padding:7px 13px;border-radius:999px;background:#e6f8f4;color:#0b4f49;font-size:13px;font-weight:700;letter-spacing:.2px;">
        Angebotsanfrage
    </div>

    <h1 class="mobile-small" style="margin:0 0 22px 0;font-size:28px;line-height:1.25;color:#17384a;font-weight:700;">
        Wir bitten um ein Reparaturangebot
    </h1>

    <p style="margin:0 0 16px 0;">Guten Tag {{ $workshopLabel }},</p>

    <p style="margin:0 0 16px 0;">
        für das unten genannte Fahrzeug liegt ein Gutachten mit {{ $positionSummary }} vor. Über den
        folgenden Link können Sie die Positionen einsehen und Ihr Angebot direkt eintragen — ein Konto
        ist dafür nicht erforderlich.
    </p>

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" class="card" style="margin:26px 0 8px 0;border:1px solid #dbece8;border-radius:14px;background:#fbfefd;">
        <tr>
            <td style="padding:20px 20px 6px 20px;font-size:13px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;color:#557080;">
                Fahrzeug
            </td>
        </tr>
        @foreach ([
            'Auftragsnummer' => $orderReference,
            'Kennzeichen' => $licensePlate,
            'Fahrzeug' => $vehicleLabel,
            'FIN' => $vin,
            'Erstzulassung' => $firstRegistration,
            'Laufleistung' => $mileage !== null ? number_format($mileage, 0, ',', '.').' km' : null,
            'Positionen' => $positionSummary,
            'Gutachtenbetrag (netto)' => $requestedTotalNet !== null ? number_format((float) $requestedTotalNet, 2, ',', '.').' €' : null,
        ] as $label => $value)
            @if (filled($value))
                <tr>
                    <td style="padding:4px 20px;font-size:14px;color:#17384a;">
                        <strong style="color:#557080;font-weight:600;">{{ $label }}:</strong> {{ $value }}
                    </td>
                </tr>
            @endif
        @endforeach
        <tr><td style="padding:0 20px 16px 20px;"></td></tr>
    </table>

    <table role="presentation" cellspacing="0" cellpadding="0" style="margin:26px 0 10px 0;">
        <tr>
            <td style="border-radius:12px;background:#0bb995;">
                <a href="{{ $quotationUrl }}" style="display:inline-block;padding:14px 26px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;">
                    Angebot abgeben
                </a>
            </td>
        </tr>
    </table>

    <p style="margin:0 0 16px 0;font-size:13px;color:#557080;">
        Der Link ist bis zum {{ $expiresOn }} gültig und ausschließlich für Sie bestimmt. Bitte leiten
        Sie ihn nicht weiter.
    </p>

    <p style="margin:0;font-size:13px;color:#557080;word-break:break-all;">
        Falls der Button nicht funktioniert: <a href="{{ $quotationUrl }}" style="color:#0bb995;">{{ $quotationUrl }}</a>
    </p>
@endsection

@section('footer-note', 'Sie erhalten diese E-Mail, weil Leasyback Sie um ein Reparaturangebot zu diesem Fahrzeug gebeten hat.')
