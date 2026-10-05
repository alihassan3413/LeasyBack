{{--
    Werkstattangebot — the workshop's own copy of one quotation.

    Written for dompdf, which is why this looks unlike the rest of the
    application's markup: no flexbox or grid (dompdf supports neither), tables
    for layout, and every image already a data: URI because dompdf runs with
    remote fetching disabled.

    Amounts arrive as decimal strings computed with bcmath and are only
    formatted here. Nothing in this file adds up money.
--}}
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Werkstattangebot {{ $reference }}</title>
    <style>
        @page { margin: 22mm 16mm 26mm 16mm; }

        body {
            margin: 0;
            font-family: DejaVu Sans, sans-serif;
            font-size: 9pt;
            line-height: 1.45;
            color: #17384a;
        }

        /* Fixed blocks repeat on every page in dompdf, which is how the footer
           and its page numbering reach page two onwards. */
        .footer {
            position: fixed;
            bottom: -16mm;
            left: 0;
            right: 0;
            height: 14mm;
            border-top: 0.7pt solid #cfdedb;
            padding-top: 2.5mm;
            font-size: 7pt;
            color: #6f8585;
        }
        .footer table { width: 100%; }
        .footer .right { text-align: right; }
        /* Only the current page. dompdf resolves counter(page) here but reports
           counter(pages) as 0 inside fixed content, and its one reliable source
           for a total is the inline-PHP canvas, which stays disabled on purpose
           — a page count is not worth executing PHP from a template. */
        .pagenum:after { content: counter(page); }

        h1 { font-size: 17pt; margin: 0 0 1mm 0; color: #0b4f49; letter-spacing: -0.2pt; }
        h2 {
            font-size: 10.5pt;
            margin: 7mm 0 2mm 0;
            color: #0b4f49;
            border-bottom: 0.7pt solid #cfdedb;
            padding-bottom: 1.2mm;
        }
        p { margin: 0 0 1.5mm 0; }

        .masthead { width: 100%; margin-bottom: 7mm; }
        .masthead td { vertical-align: top; }
        .masthead .logo { width: 46mm; }
        .masthead .logo img { width: 46mm; }
        .masthead .meta { text-align: right; font-size: 8pt; color: #557080; }
        .masthead .meta strong { color: #17384a; }
        .subtitle { color: #6f8585; font-size: 9pt; margin: 0; }

        /* Two columns of label/value without flexbox. */
        .facts { width: 100%; border-collapse: collapse; }
        .facts td { vertical-align: top; padding: 0 6mm 0 0; width: 50%; }
        .kv { width: 100%; border-collapse: collapse; }
        .kv th {
            text-align: left;
            font-weight: normal;
            color: #6f8585;
            width: 34mm;
            padding: 0.7mm 0;
            font-size: 8.5pt;
        }
        .kv td { padding: 0.7mm 0; font-size: 8.5pt; }

        table.positions { width: 100%; border-collapse: collapse; margin-top: 1mm; }
        table.positions th {
            background: #0b4f49;
            color: #ffffff;
            font-size: 7.6pt;
            text-align: left;
            padding: 1.8mm 2mm;
            font-weight: bold;
        }
        table.positions td {
            padding: 1.8mm 2mm;
            border-bottom: 0.5pt solid #e2ecea;
            font-size: 8.4pt;
            vertical-align: top;
        }
        table.positions tr { page-break-inside: avoid; }
        .num { text-align: right; white-space: nowrap; }
        /* Explicit, because dompdf's automatic column algorithm sizes from the
           text alone and then leaves the description column too narrow for two
           photos to sit side by side. */
        .col-pos { width: 8mm; }
        .col-part { width: 30mm; }
        .col-desc { width: 52mm; }
        .col-method { width: 26mm; }
        .col-money { width: 21mm; }
        .muted { color: #9bb0af; }
        .blank-cell { border-bottom: 0.7pt dotted #9bb0af; display: block; height: 3.4mm; }

        .additional h2 { color: #a9741b; border-bottom-color: #e5c37e; page-break-after: avoid; }
        .additional-intro { page-break-inside: avoid; page-break-after: avoid; }
        .additional-block { page-break-inside: avoid; }
        table.additional-table th { background: #a9741b; }
        .additional-note {
            background: #fffaf0;
            border: 0.7pt solid #e5c37e;
            padding: 2mm 2.5mm;
            margin: 0 0 2mm 0;
            font-size: 8pt;
            color: #7a5310;
        }

        /* Photos sit in their own position's description cell rather than in a
           row of their own: dompdf mis-measures a table row whose neighbour
           carries an image and prints the two on top of each other, where
           inside one cell the layout stays ordinary block flow.
           The description gets an explicit block of its own for the same
           reason — bare text beside a block element makes dompdf under-measure
           the anonymous block, so the photos landed on the last line of text.
           Both image dimensions come from the renderer, computed from each
           photo's own pixels, so nothing is ever stretched. */
        .desc { margin: 0; }
        table.thumbs { border-collapse: separate; border-spacing: 0 0; margin-top: 1.8mm; }
        table.thumbs td { padding: 0 1.2mm 0.8mm 0; border: 0; vertical-align: top; }
        table.thumbs img { border: 0.5pt solid #d8e4e2; }

        table.totals { width: 96mm; border-collapse: collapse; margin-left: auto; margin-top: 4mm; }
        table.totals th { text-align: left; font-weight: normal; color: #557080; padding: 1.2mm 2mm; font-size: 8.5pt; white-space: nowrap; }
        table.totals td { text-align: right; padding: 1.2mm 2mm; font-size: 8.5pt; white-space: nowrap; }
        table.totals tr.grand th, table.totals tr.grand td {
            border-top: 0.9pt solid #0b4f49;
            font-weight: bold;
            color: #0b4f49;
            font-size: 10pt;
            padding-top: 1.8mm;
        }
        .totals-wrap { page-break-inside: avoid; }

        .notice {
            border-left: 1.6pt solid #0bb995;
            background: #f4faf8;
            padding: 2mm 3mm;
            margin: 3mm 0 0 0;
            font-size: 8pt;
            color: #2c5c56;
        }
        .notice-warn { border-left-color: #c0392b; background: #fdf5f4; color: #8c2f23; }
        .empty { color: #9bb0af; font-size: 8.5pt; font-style: italic; }

        /* Bildanhang: always starts on a fresh page; every photo and its caption
           stay together. */
        .appendix { page-break-before: always; }
        .appendix h2 { margin-top: 0; }
        table.appendix-photo { border-collapse: collapse; margin: 5mm 0 0 0; }
        table.appendix-photo td { padding: 0; border: 0; }
        .appendix-caption { font-size: 8.5pt; margin-bottom: 1.5mm; color: #17384a; }
        table.appendix-photo img { display: block; border: 0.5pt solid #d8e4e2; }
    </style>
</head>
<body>

<div class="footer">
    <table>
        <tr>
            <td>
                <strong>{{ $company['name'] }}</strong> · {{ $company['address'] }}<br>
                {{ $company['email'] }} · {{ $company['phone'] }} · {{ $company['website'] }}
            </td>
            <td class="right">
                Werkstattangebot<br>
                {{ $reference }} · Seite <span class="pagenum"></span>
            </td>
        </tr>
    </table>
</div>

<table class="masthead">
    <tr>
        <td class="logo">
            @if ($logo)
                <img src="{{ $logo }}" alt="LeasyBack">
            @else
                <strong style="font-size:15pt;color:#0b4f49;">LeasyBack</strong>
            @endif
        </td>
        <td class="meta">
            <strong>{{ $company['name'] }}</strong><br>
            {{ $company['address'] }}<br>
            {{ $company['phone'] }}
        </td>
    </tr>
</table>

<h1>Werkstattangebot</h1>
<p class="subtitle">Reparaturangebot zum Gutachten des Fahrzeugs · alle Beträge netto in Euro</p>

<h2>Angebotsdaten</h2>
<table class="facts">
    <tr>
        <td>
            <table class="kv">
                <tr><th>Werkstatt</th><td>{{ $workshop_label }}</td></tr>
                @if ($company_name)
                    <tr><th>Firma</th><td>{{ $company_name }}</td></tr>
                @endif
                @if ($contact_person)
                    <tr><th>Ansprechpartner</th><td>{{ $contact_person }}</td></tr>
                @endif
                <tr><th>Auftragsnummer</th><td><strong>{{ $reference }}</strong></td></tr>
            </table>
        </td>
        <td>
            <table class="kv">
                <tr><th>Datum</th><td>{{ $printed_at }}</td></tr>
                @if ($submitted_at)
                    <tr><th>Eingereicht am</th><td>{{ $submitted_at }}</td></tr>
                @elseif ($expires_at)
                    <tr><th>Gültig bis</th><td>{{ $expires_at }}</td></tr>
                @endif
                @if ($earliest_repair_start)
                    <tr><th>Reparaturbeginn</th><td>{{ $earliest_repair_start }}</td></tr>
                @endif
                @if ($processing_days !== null)
                    <tr><th>Bearbeitungsdauer</th><td>{{ $processing_days }} Tage</td></tr>
                @endif
            </table>
        </td>
    </tr>
</table>

@if ($vehicle)
    <h2>Fahrzeug</h2>
    <table class="facts">
        <tr>
            <td>
                <table class="kv">
                    <tr><th>Kennzeichen</th><td><strong>{{ $vehicle['license_plate'] ?: '—' }}</strong></td></tr>
                    <tr><th>Hersteller</th><td>{{ $vehicle['make'] ?: '—' }}</td></tr>
                    <tr><th>Modell</th><td>{{ $vehicle['model'] ?: '—' }}</td></tr>
                </table>
            </td>
            <td>
                <table class="kv">
                    <tr><th>FIN</th><td>{{ $vehicle['vin'] ?: '—' }}</td></tr>
                    <tr><th>Erstzulassung</th><td>{{ $vehicle['first_registration'] ?: '—' }}</td></tr>
                    <tr><th>Laufleistung</th><td>{{ $vehicle['mileage'] ?: '—' }}</td></tr>
                </table>
            </td>
        </tr>
    </table>
@endif

<h2>Gutachtenpositionen</h2>

@if (count($positions) === 0)
    <p class="empty">Zu diesem Auftrag sind keine Gutachtenpositionen erfasst.</p>
@else
    <table class="positions">
        <thead>
            <tr>
                <th class="col-pos">Pos.</th>
                <th class="col-part">Bauteil</th>
                <th class="col-desc">Schadenbeschreibung</th>
                <th class="col-method">Reparaturweg</th>
                @if ($shows_appraisal_amounts)
                    <th class="col-money num">Gutachten netto</th>
                @endif
                <th class="col-money num">Werkstatt netto</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($positions as $position)
                <tr>
                    <td class="num">{{ $position['number'] }}</td>
                    <td>{{ $position['component'] }}</td>
                    <td>
                        <div class="desc">{{ $position['damage_description'] ?: '—' }}</div>
                        @include('pdf._damage-images', ['images' => $position['images'], 'label' => $position['component']])
                    </td>
                    <td>{{ $position['repair_method'] ?: '—' }}</td>
                    @if ($shows_appraisal_amounts)
                        <td class="num">{{ $position['appraisal_amount_net'] }}</td>
                    @endif
                    <td class="num">
                        @if ($position['not_repairable'])
                            <span class="muted">nicht reparierbar</span>
                        @elseif ($position['workshop_amount_net'] !== null)
                            {{ $position['workshop_amount_net'] }}
                        @else
                            {{-- Nothing priced yet: a ruled cell, so a printed sheet
                                 can be filled in by hand at the vehicle. --}}
                            <span class="blank-cell"></span>
                        @endif
                    </td>
                </tr>
                @if (count($position['images']))
                @endif
            @endforeach
        </tbody>
    </table>
@endif

@if (count($additional_positions))
    <div class="additional additional-block">
        <div class="additional-intro">
            <h2>Zusätzliche Schäden – von der Werkstatt festgestellt</h2>

            <p class="additional-note">
                Diese Positionen sind <strong>nicht Teil des Gutachtens</strong>. Sie wurden von der
                Werkstatt zusätzlich festgestellt und von ihr bepreist.
            </p>
        </div>

        <table class="positions additional-table">
            <thead>
                <tr>
                    <th class="col-pos">Pos.</th>
                    <th class="col-part">Bauteil</th>
                    <th class="col-desc">Schadenbeschreibung</th>
                    <th class="col-method">Reparaturweg</th>
                    <th class="col-money num">Preis netto</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($additional_positions as $position)
                    <tr>
                        <td class="num">Z{{ $position['number'] }}</td>
                        <td>{{ $position['component'] }}</td>
                        <td>
                            <div class="desc">{{ $position['damage_description'] ?: '—' }}</div>
                            @include('pdf._damage-images', ['images' => $position['images'], 'label' => $position['component']])
                        </td>
                        <td>{{ $position['repair_method'] ?: '—' }}</td>
                        <td class="num">{{ $position['amount_net'] ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

<div class="totals-wrap">
    <table class="totals">
        @if ($appraisal_total_net !== null)
            <tr><th>Gutachten netto</th><td>{{ $appraisal_total_net }}</td></tr>
        @endif
        <tr><th>Werkstatt netto (Gutachtenpositionen)</th><td>{{ $workshop_total_net }}</td></tr>
        @if (count($additional_positions))
            <tr><th>Zusätzliche Schäden netto</th><td>{{ $additional_total_net }}</td></tr>
        @endif
        <tr class="grand"><th>Werkstattangebot Gesamt netto</th><td>{{ $grand_total_net }}</td></tr>
    </table>
</div>

@if ($cannot_repair_for_amount)
    <p class="notice notice-warn">
        <strong>Hinweis der Werkstatt:</strong> Eine Reparatur zu den Gutachtenbeträgen ist nicht möglich.
        @if ($cannot_repair_note){{ $cannot_repair_note }}@endif
    </p>
@endif

@if ($is_draft)
    <p class="notice">
        <strong>Entwurf:</strong> Dieses Dokument zeigt die im Online-Formular eingetragenen Preise.
        Sie sind noch nicht eingereicht — erst das Absenden dort gilt als Angebot.
        @if (count($additional_positions))
            Fotos zu zusätzlichen Schäden erscheinen erst nach dem Absenden.
        @endif
    </p>
@elseif (! $is_submitted)
    <p class="notice">
        Dieses Dokument bildet den aktuellen Stand der Anfrage ab. Die Preise der Werkstatt sind
        noch nicht eingereicht — bitte tragen Sie sie im Online-Formular ein. Erst das Absenden
        dort gilt als Angebot.
    </p>
@endif

@if (count($image_appendix))
    {{--
        The photos again, large. Each photo and its caption share one table
        cell: dompdf never splits a single row, so a caption cannot be left at
        the bottom of a page without its photo. page-break-inside: avoid is
        deliberately not used — dompdf pushed such blocks to the next page with
        room to spare, leaving one photo per page. Both dimensions come from
        the renderer, so a photo is scaled to fit its box without stretching.
    --}}
    <div class="appendix">
        <h2>Bildanhang</h2>
        <p class="subtitle">Schadenbilder in voller Größe, in der Reihenfolge der Positionen.</p>

        @foreach ($image_appendix as $section)
            @foreach ($section['images'] as $index => $image)
                <table class="appendix-photo">
                    <tr>
                        <td>
                            <div class="appendix-caption">
                                <strong>{{ $section['label'] }} · {{ $section['component'] }}</strong>
                                <span class="muted">· Bild {{ $index + 1 }} von {{ count($section['images']) }}</span>
                            </div>
                            <img src="{{ $image['src'] }}"
                                 width="{{ $image['width'] }}"
                                 height="{{ $image['height'] }}"
                                 alt="Schadenbild {{ $index + 1 }} zu {{ $section['label'] }}: {{ $section['component'] }}">
                        </td>
                    </tr>
                </table>
            @endforeach
        @endforeach
    </div>
@endif

</body>
</html>
