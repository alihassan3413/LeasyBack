@extends('emails.layout')

@section('title', $heading)
@section('preheader', $preheader)

@section('content')
    @php
        $badgePalette = [
            'delivered' => ['bg' => '#e6f8f4', 'fg' => '#0b6b52'],
            'confirmed' => ['bg' => '#e6f8f4', 'fg' => '#0b6b52'],
            'workshop' => ['bg' => '#fff2e0', 'fg' => '#8a5200'],
            'reworkshop' => ['bg' => '#fff2e0', 'fg' => '#8a5200'],
            'cancelled' => ['bg' => '#fdeaea', 'fg' => '#8f2020'],
            'discarded' => ['bg' => '#f1f4f4', 'fg' => '#556b6b'],
        ];
        $badge = $badgePalette[$data->statusValue] ?? ['bg' => '#e9f1f5', 'fg' => '#17384a'];
        $details = $data->details();
    @endphp

    <div style="display:inline-block;margin:0 0 16px 0;padding:7px 13px;border-radius:999px;background:#e6f8f4;color:#0b4f49;font-size:13px;font-weight:700;letter-spacing:.2px;">
        {{ $eyebrow }}
    </div>

    <h1 class="mobile-small" style="margin:0 0 22px 0;font-size:28px;line-height:1.25;color:#17384a;font-weight:700;">
        {{ $heading }}
    </h1>

    <p style="margin:0 0 16px 0;">Hallo {{ $data->recipientName }},</p>

    @foreach ($paragraphs as $paragraph)
        <p style="margin:0 0 16px 0;">{{ $paragraph }}</p>
    @endforeach

    @if (count($details) > 0)
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" class="card" style="margin:26px 0 8px 0;border:1px solid #dbece8;border-radius:14px;background:#fbfefd;">
            <tr>
                <td style="padding:20px 20px 6px 20px;font-size:13px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;color:#557080;">
                    Details
                </td>
            </tr>
            <tr>
                <td style="padding:0 20px 18px 20px;">
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="font-size:15px;line-height:1.55;">
                        @foreach ($details as $label => $value)
                            <tr>
                                <td class="detail-label" width="40%" valign="top" style="padding:8px 14px 8px 0;color:#557080;">{{ $label }}</td>
                                <td class="detail-value" valign="top" style="padding:8px 0;color:#17384a;font-weight:600;">
                                    @if ($label === 'Aktueller Status')
                                        <span style="display:inline-block;padding:5px 12px;border-radius:999px;background:{{ $badge['bg'] }};color:{{ $badge['fg'] }};font-size:13px;font-weight:700;">{{ $value }}</span>
                                    @else
                                        {{ $value }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </table>
                </td>
            </tr>
        </table>
    @endif

    @if ($data->actionUrl)
        <table role="presentation" cellspacing="0" cellpadding="0" style="margin:26px 0 22px 0;">
            <tr>
                <td align="center" bgcolor="#0bb995" style="border-radius:13px;">
                    <a class="button" href="{{ $data->actionUrl }}" target="_blank" style="display:inline-block;padding:17px 34px;font-size:17px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:13px;">
                        {{ $ctaLabel }}
                    </a>
                </td>
            </tr>
        </table>
    @endif

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
