@extends('emails.layout')

@section('title', 'Ihr Anmeldecode')
@section('preheader', 'Ihr Anmeldecode ist ' . $minutes . ' Minuten gültig.')

@section('content')
    <h1 style="margin:0 0 12px 0;font-size:22px;color:#17384a;">Ihr Anmeldecode</h1>

    <p style="margin:0 0 18px 0;font-size:15px;line-height:1.6;color:#17384a;">
        Hallo {{ $name }},<br>
        bitte geben Sie diesen Code ein, um die Anmeldung abzuschließen.
    </p>

    <p style="margin:0 0 18px 0;font-size:34px;font-weight:bold;letter-spacing:8px;color:#0b4f49;">
        {{ $code }}
    </p>

    <p style="margin:0 0 18px 0;font-size:14px;line-height:1.6;color:#557080;">
        Der Code ist {{ $minutes }} Minuten gültig und kann nur einmal verwendet werden.
    </p>

    <p style="margin:0;font-size:14px;line-height:1.6;color:#557080;">
        Wenn Sie sich nicht anmelden wollten, ignorieren Sie diese E-Mail und ändern Sie
        bitte Ihr Passwort — jemand kennt es möglicherweise.
    </p>
@endsection

@section('footer-note', 'Sie erhalten diese E-Mail, weil für Ihr LeasyBack-Konto eine Anmeldung mit Bestätigungscode angefordert wurde.')
