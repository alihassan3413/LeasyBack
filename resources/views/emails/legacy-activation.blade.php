@extends('emails.layout')

@section('title', 'Ihr Konto im neuen LeasyBack-Portal')
@section('preheader', 'Legen Sie Ihr Passwort fest, um Ihr Konto im neuen LeasyBack-Portal zu aktivieren.')

@section('content')
    <h1 style="margin:0 0 12px 0;font-size:22px;color:#17384a;">Willkommen im neuen LeasyBack-Portal</h1>

    <p style="margin:0 0 18px 0;font-size:15px;line-height:1.6;color:#17384a;">
        Hallo{{ $name ? ' '.$name : '' }},<br>
        Ihr LeasyBack-Konto ist in unser neues Portal umgezogen — mit Ihrem Unternehmen, Ihren Fahrzeugen und Aufträgen.
        Ihr bisheriges Passwort konnte aus Sicherheitsgründen nicht übernommen werden. Legen Sie bitte einmalig ein neues fest:
    </p>

    <p style="margin:0 0 22px 0;">
        <a href="{{ $activationUrl }}" class="button"
           style="display:inline-block;padding:13px 26px;border-radius:999px;background:#0bb995;color:#ffffff;font-size:15px;font-weight:bold;text-decoration:none;">
            Konto aktivieren
        </a>
    </p>

    <p style="margin:0 0 18px 0;font-size:14px;line-height:1.6;color:#557080;">
        Der Link ist {{ $expiresInDays }} Tage gültig und kann nur einmal verwendet werden. Danach melden Sie sich wie gewohnt mit Ihrer
        E-Mail-Adresse und dem neuen Passwort an.
    </p>

    <p style="margin:0 0 18px 0;font-size:14px;line-height:1.6;color:#557080;">
        Ist der Link abgelaufen? Über <a href="{{ $forgotUrl }}" style="color:#0bb995;">„Passwort vergessen"</a> erhalten Sie jederzeit einen neuen.
    </p>

    <p style="margin:0;font-size:13px;line-height:1.6;color:#557080;">
        Falls der Button nicht funktioniert, kopieren Sie diesen Link in Ihren Browser:<br>
        <span style="word-break:break-all;">{{ $activationUrl }}</span>
    </p>
@endsection

@section('footer-note', 'Sie erhalten diese E-Mail einmalig, weil Ihr Konto aus dem bisherigen LeasyBack-Portal übernommen wurde. LeasyBack fragt Sie nie per E-Mail nach Ihrem Passwort.')
