<?php

return [
    'pdftotext' => [
        'binary' => env('PDFTOTEXT_BINARY', 'pdftotext'),
        'timeout' => (int) env('PDFTOTEXT_TIMEOUT', 30),
        'max_pages' => (int) env('PDFTOTEXT_MAX_PAGES', 80),
        'max_bytes' => (int) env('PDFTOTEXT_MAX_BYTES', 52428800),
        'min_characters' => (int) env('PDFTOTEXT_MIN_CHARACTERS', 400),
    ],

    'images' => [
        'binary' => env('PDFIMAGES_BINARY', 'pdfimages'),
        'timeout' => (int) env('PDFIMAGES_TIMEOUT', 60),
        'max_pages' => (int) env('PDFIMAGES_MAX_PAGES', 80),
        'max_images' => (int) env('PDFIMAGES_MAX_IMAGES', 60),
        'min_width' => 400,
        'min_height' => 260,
        'min_pixels' => 150000,
        'min_aspect' => 0.25,
        'max_aspect' => 4.0,
        'extensions' => ['jpg', 'jpeg', 'png'],
        'damage_headings' => [
            '/^Beschädigungsfotos$/iu',
            '/^Beschädigungsbilder$/iu',
            '/^Schadenfotos$/iu',
            '/^Schadensfotos$/iu',
            '/^Schadenbilder$/iu',
            '/^Fotos der Beschädigungen$/iu',
        ],
        'overview_headings' => [
            '/^Übersichtsfotos$/iu',
            '/^Ansichtsfotos$/iu',
            '/^Fahrzeugfotos$/iu',
            '/^Übersichtsbilder$/iu',
        ],
        'damage_caption' => '/Beschädigung\s*#?\s*\d{1,3}\s*[:.]/iu',
        'damage_number' => '/Beschädigung\s*#?\s*(\d{1,3})\s*[:.]/iu',
    ],

    'sections' => [
        'damage' => [
            '/Wertmindernde\s+Faktoren/iu',
        ],
        'missing_parts' => [
            '/^Fehlteile\s*:?$/iu',
        ],
        'stop' => [
            '/Gebrauchsspuren/iu',
            '/Schadenzusammenfassung/iu',
            '/Besichtigungsbedingungen/iu',
        ],
        'appendix' => [
            '/Beschädigungsfotos/iu',
        ],
    ],

    'noise' => [
        '/^(?:Nr\.?\s|Summe\b|TÜV\b|DEKRA\b|PROTOKOLLNUMMER|Bei Rückfragen|Datum:|Seite\s+\d|FIN:|=====)/iu',
        '/^\d{2}\.\d{2}\.\d{4}/u',
        '/(?:Protokollnummer|Berichts-Nr).*Seite/iu',
        '/^(?:Bauteilgruppe|Beschreibung|Reparaturempfehlung|Reparaturkosten|Minderwert)\b/iu',
    ],

    'component_groups' => [
        'Verkleidungen/Abdeckungen',
        'Hintere Dachsäulenverkleidung',
        'Türverkleidung hinten links',
        'Türverkleidung vorne links',
        'Stossfänger hinten',
        'Stoßfänger hinten',
        'Stossfänger vorn',
        'Stoßfänger vorn',
        'Seitenwand rechts',
        'Seitenwand links',
        'Kotflügel rechts',
        'Kotflügel links',
        'Tür hinten rechts',
        'Tür hinten links',
        'Tür vorn rechts',
        'Tür vorn links',
        'Heckklappe/-tür',
        'Heckleuchte',
        'Fahrzeugdach',
        'Motorhaube',
        'Schweller links',
        'Schweller rechts',
        'Sitzbezug hinten rechts',
        'Sitzbezug hinten links',
        'Sitzbezug vorne rechts',
        'Sitzbezug vorne links',
        'Verglasung',
        'Sonstiges',
        'Ausrüstung',
    ],

    'repair_terms' => [
        'instandsetzen und lackieren',
        'sanft instandsetzen',
        'Instandsetzen + lackieren',
        'auslegen / polieren',
        'auslegen und polieren',
        'Smart Repair',
        'durchführen',
        'erneuern',
        'Ersetzen',
        'lackieren',
        'instandsetzen',
        'polieren',
        'reinigen',
    ],

    'damage_terms' => [
        'gebrochen / gerissen',
        'Delle / Lackschaden',
        'Beklebt / beschriftet',
        'verschmutzt',
        'beschädigt',
        'Steinschlag',
        'Deformiert',
        'Verschlissen',
        'Delle(n)',
        'Kratzer',
        'fällig',
        'Riss',
    ],

    'missing_part' => [
        'suffix' => 'fehlt',
        'repair_method' => 'ersetzen',
        'strip_prefix' => '/^Ausrüstung\s*-?\s*/iu',
    ],

    'header' => [
        'appraisal_number' => [
            '/(?:Gutachten(?:nummer|\s*-?\s*Nr\.?)|Berichts\s*-?\s*Nr\.?|Protokollnummer)\s*[:.]?\s*([A-Z0-9][A-Z0-9\/-]{3,29})/iu',
        ],
        'appraisal_date' => [
            '/(?:Gutachtendatum|Besichtigungsdatum|Datum\s+der\s+Besichtigung|Datum)\s*[:.]?\s*(\d{2}\.\d{2}\.\d{4})/iu',
        ],
        'vin' => [
            '/(?:FIN|VIN|Fahrgestellnummer|Fahrzeug-Identifizierungsnummer)\s*[:.]?\s*([A-HJ-NPR-Z0-9]{17})\b/iu',
        ],
        'currency' => [
            'EUR' => '/(?:€|\bEUR\b)/u',
            'CHF' => '/\bCHF\b/u',
        ],
        'max_pages' => 4,
    ],

    'totals' => [
        'tolerance' => '0.02',
        'patterns' => [
            ['pattern' => '/Gesamtsumme\s*\(ohne\s*Mwst?\.?\)/iu', 'priority' => 3, 'amount' => 'last'],
            ['pattern' => '/Summe\s+Minderwerte/iu', 'priority' => 2, 'amount' => 'first'],
            ['pattern' => '/abrechnungsrelevante\s+Minderwerte/iu', 'priority' => 2, 'amount' => 'first'],
        ],
    ],

    'confidence' => [
        'structured' => 0.9,
        'keyword' => 0.7,
        'fallback' => 0.45,
        'missing_part' => 0.8,
    ],

    'limits' => [
        'component' => 255,
        'damage_description' => 2000,
        'repair_method' => 255,
        'source_text' => 500,
        'rows' => 200,
    ],
];
