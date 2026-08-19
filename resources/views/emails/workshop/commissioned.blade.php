@extends('emails.layout')

@section('title', 'Reparaturauftrag '.$orderReference)
@section('preheader', 'Ihr Angebot wurde angenommen — Reparaturauftrag '.$orderReference)

@section('content')
    <div style="display:inline-block;margin:0 0 16px 0;padding:7px 13px;border-radius:999px;background:#e6f8f4;color:#0b4f49;font-size:13px;font-weight:700;letter-spacing:.2px;">
        Reparaturauftrag
    </div>

    <h1 class="mobile-small" style="margin:0 0 22px 0;font-size:28px;line-height:1.25;color:#17384a;font-weight:700;">
        Ihr Angebot wurde angenommen
    </h1>

    <p style="margin:0 0 16px 0;">Guten Tag {{ $workshopName }},</p>

    <p style="margin:0 0 16px 0;">
        vielen Dank für Ihr Angebot. Der Auftraggeber hat es angenommen und wir beauftragen Sie hiermit
        verbindlich mit der Instandsetzung.
    </p>

    <p style="margin:0 0 16px 0;">{{ $instruction }}</p>

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" class="card" style="margin:26px 0 8px 0;border:1px solid #dbece8;border-radius:14px;background:#fbfefd;">
        <tr>
            <td style="padding:20px 20px 6px 20px;font-size:13px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;color:#557080;">
                Auftrag
            </td>
        </tr>
        @foreach ([
            'Auftragsnummer' => $orderReference,
            'Kennzeichen' => $licensePlate,
            'Fahrzeug' => $vehicleLabel,
            'FIN' => $vin,
            'Frühester Reparaturbeginn (Ihr Angebot)' => $earliestRepairStart,
            'Bearbeitungsdauer (Ihr Angebot)' => $processingDays !== null ? $processingDays.' Arbeitstage' : null,
            'Abgestimmter Reparaturbeginn' => $confirmedRepairStart,
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

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" class="card" style="margin:18px 0 8px 0;border:1px solid #dbece8;border-radius:14px;background:#ffffff;">
        <tr>
            <td colspan="2" style="padding:20px 20px 10px 20px;font-size:13px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;color:#557080;">
                Freigegebene Positionen
            </td>
        </tr>
        @foreach ($positions as $position)
            <tr>
                <td style="padding:8px 8px 8px 20px;font-size:14px;color:#17384a;border-top:1px solid #eef4f3;">
                    {{ $position['component'] }}
                    @if ($position['not_repairable'])
                        <span style="color:#8f2020;font-size:12px;">— von Ihnen als nicht instandsetzbar gemeldet</span>
                    @elseif (filled($position['repair_method']))
                        <span style="display:block;color:#6b8080;font-size:12px;">{{ $position['repair_method'] }}</span>
                    @endif
                </td>
                <td style="padding:8px 20px 8px 8px;font-size:14px;color:#17384a;text-align:right;white-space:nowrap;border-top:1px solid #eef4f3;">
                    {{ $position['amount_net'] !== null ? number_format((float) $position['amount_net'], 2, ',', '.').' €' : '—' }}
                </td>
            </tr>
        @endforeach
        @if (filled($totalNet))
            <tr>
                <td style="padding:12px 8px 18px 20px;font-size:14px;font-weight:700;color:#17384a;border-top:1px solid #dbece8;">
                    Gesamt (netto)
                </td>
                <td style="padding:12px 20px 18px 8px;font-size:14px;font-weight:700;color:#17384a;text-align:right;white-space:nowrap;border-top:1px solid #dbece8;">
                    {{ number_format((float) $totalNet, 2, ',', '.') }} €
                </td>
            </tr>
        @endif
    </table>

    <p style="margin:20px 0 0 0;font-size:14px;color:#557080;">
        Alle Beträge netto, entsprechend Ihrem eingereichten Angebot. Bei Rückfragen erreichen Sie uns
        über die unten stehenden Kontaktdaten.
    </p>
@endsection

@section('footer-note', 'Sie erhalten diese E-Mail, weil Ihr Werkstattangebot zu diesem Auftrag angenommen wurde.')
