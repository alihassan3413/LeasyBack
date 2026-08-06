Hallo,

{{ $invitedByName }} hat Sie eingeladen, dem Unternehmen "{{ $companyName }}" im {{ config('mail.from.name') }}-Portal beizutreten.

Sie behalten dabei Ihr bestehendes Konto. Falls Sie bereits einen privaten Bereich bei {{ config('mail.from.name') }} nutzen, bleibt dieser vollstaendig erhalten - Sie koennen jederzeit zwischen Ihrem privaten Bereich und {{ $companyName }} wechseln.

IHRE EINLADUNG
--------------
Unternehmen: {{ $companyName }}
Eingeladen von: {{ $invitedByName }}
Rolle: {{ $roleLabel }}
Eingeladene Adresse: {{ $invitedEmail }}
Sichtbare Fahrzeuge: {{ $vehicleScopeLabel }}
Gueltig bis: {{ $expiresAtLabel }}
@if (count($permissionLabels) > 0)

IHRE BERECHTIGUNGEN
-------------------
@foreach ($permissionLabels as $permissionLabel)
- {{ $permissionLabel }}
@endforeach
@endif

EINLADUNG ANNEHMEN
------------------
{{ $acceptUrl }}

SICHERHEITSHINWEIS
------------------
Diese Einladung gilt ausschliesslich fuer {{ $invitedEmail }} und kann nur nach Anmeldung mit dieser Adresse angenommen werden. Der Link laeuft am {{ $expiresAtLabel }} ab. Leiten Sie ihn nicht weiter. Wenn Sie diese Einladung nicht erwartet haben, ignorieren Sie diese E-Mail - ohne Ihre Bestaetigung passiert nichts.

Bei Fragen erreichen Sie uns unter {{ config('mail_notifications.support.email') }} oder telefonisch unter {{ config('mail_notifications.support.phone') }}.

Freundliche Gruesse
Ihr {{ config('mail.from.name') }}-Team

--
{{ config('mail_notifications.branding.company_name') }}
{{ config('mail_notifications.branding.company_address') }}
{{ config('mail_notifications.branding.website_url') }}
