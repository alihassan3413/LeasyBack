@extends('emails.layout')

@section('title', "Einladung zu {$companyName}")
@section('preheader', "{$invitedByName} lädt Sie ein, {$companyName} bei " . config('mail.from.name') . ' beizutreten.')
@section('footer-note', 'Sie erhalten diese E-Mail, weil Sie zu einem Unternehmenskonto im LeasyBack-Portal eingeladen wurden.')

@section('content')
    <div style="display:inline-block;margin:0 0 16px 0;padding:7px 13px;border-radius:999px;background:#e6f8f4;color:#0b4f49;font-size:13px;font-weight:700;letter-spacing:.2px;">
        Einladung ins Team
    </div>

    <h1 class="mobile-small" style="margin:0 0 22px 0;font-size:28px;line-height:1.25;color:#17384a;font-weight:700;">
        Sie wurden zu {{ $companyName }} eingeladen
    </h1>

    <p style="margin:0 0 16px 0;">Hallo,</p>

    <p style="margin:0 0 16px 0;">
        {{ $invitedByName }} hat Sie eingeladen, dem Unternehmen <strong>{{ $companyName }}</strong>
        im {{ config('mail.from.name') }}-Portal beizutreten.
    </p>

    <p style="margin:0 0 16px 0;">
        Sie behalten dabei Ihr bestehendes Konto. Falls Sie bereits einen privaten Bereich bei
        {{ config('mail.from.name') }} nutzen, bleibt dieser vollständig erhalten — Sie können jederzeit
        zwischen Ihrem privaten Bereich und {{ $companyName }} wechseln.
    </p>

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" class="card" style="margin:26px 0 8px 0;border:1px solid #dbece8;border-radius:14px;background:#fbfefd;">
        <tr>
            <td style="padding:20px 20px 6px 20px;font-size:13px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;color:#557080;">
                Ihre Einladung
            </td>
        </tr>
        <tr>
            <td style="padding:0 20px 18px 20px;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="font-size:15px;line-height:1.55;">
                    <tr>
                        <td class="detail-label" width="40%" valign="top" style="padding:8px 14px 8px 0;color:#557080;">Unternehmen</td>
                        <td class="detail-value" valign="top" style="padding:8px 0;color:#17384a;font-weight:600;">{{ $companyName }}</td>
                    </tr>
                    <tr>
                        <td class="detail-label" width="40%" valign="top" style="padding:8px 14px 8px 0;color:#557080;">Eingeladen von</td>
                        <td class="detail-value" valign="top" style="padding:8px 0;color:#17384a;font-weight:600;">{{ $invitedByName }}</td>
                    </tr>
                    <tr>
                        <td class="detail-label" width="40%" valign="top" style="padding:8px 14px 8px 0;color:#557080;">Rolle</td>
                        <td class="detail-value" valign="top" style="padding:8px 0;color:#17384a;font-weight:600;">
                            <span style="display:inline-block;padding:5px 12px;border-radius:999px;background:#e6f8f4;color:#0b6b52;font-size:13px;font-weight:700;">{{ $roleLabel }}</span>
                        </td>
                    </tr>
                    <tr>
                        <td class="detail-label" width="40%" valign="top" style="padding:8px 14px 8px 0;color:#557080;">Eingeladene Adresse</td>
                        <td class="detail-value" valign="top" style="padding:8px 0;color:#17384a;font-weight:600;">{{ $invitedEmail }}</td>
                    </tr>
                    <tr>
                        <td class="detail-label" width="40%" valign="top" style="padding:8px 14px 8px 0;color:#557080;">Sichtbare Fahrzeuge</td>
                        <td class="detail-value" valign="top" style="padding:8px 0;color:#17384a;font-weight:600;">{{ $vehicleScopeLabel }}</td>
                    </tr>
                    <tr>
                        <td class="detail-label" width="40%" valign="top" style="padding:8px 14px 8px 0;color:#557080;">Gültig bis</td>
                        <td class="detail-value" valign="top" style="padding:8px 0;color:#17384a;font-weight:600;">{{ $expiresAtLabel }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    @if (count($permissionLabels) > 0)
        <p style="margin:20px 0 8px 0;font-size:13px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;color:#557080;">
            Ihre Berechtigungen
        </p>
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 8px 0;font-size:15px;line-height:1.55;">
            @foreach ($permissionLabels as $permissionLabel)
                <tr>
                    <td valign="top" width="18" style="padding:4px 8px 4px 0;color:#0bb995;font-weight:700;">&bull;</td>
                    <td valign="top" style="padding:4px 0;color:#17384a;">{{ $permissionLabel }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <table role="presentation" cellspacing="0" cellpadding="0" style="margin:26px 0 18px 0;">
        <tr>
            <td align="center" bgcolor="#0bb995" style="border-radius:13px;">
                <a class="button" href="{{ $acceptUrl }}" target="_blank" style="display:inline-block;padding:17px 34px;font-size:17px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:13px;">
                    Einladung annehmen
                </a>
            </td>
        </tr>
    </table>

    <p style="margin:0 0 6px 0;font-size:14px;line-height:1.6;color:#557080;">
        Falls der Button nicht funktioniert, kopieren Sie diesen Link in Ihren Browser:
    </p>
    <p style="margin:0 0 20px 0;font-size:14px;line-height:1.6;word-break:break-all;">
        <a href="{{ $acceptUrl }}" target="_blank" style="color:#0bb995;text-decoration:underline;">{{ $acceptUrl }}</a>
    </p>

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" class="card" style="margin:0 0 20px 0;border:1px solid #dbece8;border-radius:14px;background:#f8fbfa;">
        <tr>
            <td style="padding:16px 18px;font-size:14px;line-height:1.6;color:#557080;">
                <strong style="color:#17384a;">Sicherheitshinweis:</strong>
                Diese Einladung gilt ausschließlich für <strong>{{ $invitedEmail }}</strong> und kann nur nach
                Anmeldung mit dieser Adresse angenommen werden. Der Link läuft am {{ $expiresAtLabel }} ab.
                Leiten Sie ihn nicht weiter. Wenn Sie diese Einladung nicht erwartet haben, ignorieren Sie
                diese E-Mail — ohne Ihre Bestätigung passiert nichts.
            </td>
        </tr>
    </table>

    <p style="margin:0 0 16px 0;font-size:15px;line-height:1.6;color:#557080;">
        Bei Fragen erreichen Sie uns unter
        <a href="mailto:{{ config('mail_notifications.support.email') }}" style="color:#0bb995;text-decoration:underline;">{{ config('mail_notifications.support.email') }}</a>
        oder telefonisch unter {{ config('mail_notifications.support.phone') }}.
    </p>

    <p style="margin:0;">
        Freundliche Grüße<br>
        Ihr {{ config('mail.from.name') }}-Team
    </p>
@endsection
