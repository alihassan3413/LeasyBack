<?php

/*
 * Base44 → LeasyBack V2 importer (php artisan legacy:import).
 *
 * The export contains real customer data and must live OUTSIDE the repository:
 * point LEGACY_IMPORT_SOURCE_PATH at the folder holding the CSV files.
 */
return [

    'source_path' => env('LEGACY_IMPORT_SOURCE_PATH'),

    /** Where reconciliation reports are written (private, never committed). */
    'report_path' => env('LEGACY_IMPORT_REPORT_PATH'),

    'files' => [
        'kunde' => 'Kunde_export.csv',
        'users' => 'LeasyBack_Flottenmanagement-users.csv',
        'fahrzeug' => 'Fahrzeug_export.csv',
        'auftrag' => 'Auftrag_export.csv',
        'kommentar' => 'Auftragskommentar_export.csv',
        'historie' => 'AuftragStatushistorie_export.csv',
        'dateianhang' => 'Dateianhang_export.csv',
        'lead' => 'FahrzeugLead_export.csv',
        'einladung' => 'PendingEinladung_export.csv',
        'benachrichtigung' => 'Benachrichtigung_export.csv',
    ],

    /** Timestamps in the export are UTC. */
    'source_timezone' => 'UTC',

    /** Authors on these domains are LeasyBack staff in the message thread. */
    'staff_email_domains' => ['leasyback.com'],

    /** Fields V2 requires but Base44 may not have; every use is reported. */
    'placeholders' => [
        'street' => 'Adresse nicht hinterlegt',
        'number' => '-',
        'zip_code' => '00000',
        'city' => 'Unbekannt',
        'country' => 'Deutschland',
        'contact_first_name' => 'Ansprechpartner',
        'contact_last_name' => 'unbekannt',
    ],

    /**
     * A company whose only link to the export is a pending invitation counts as
     * relevant, so the invited person can be re-invited into it after cutover.
     */
    'company_relevant_when_invited' => true,

    /** Vehicles with this manufacturer are test data unless an order uses them. */
    'dummy_manufacturer' => 'DUMMY',

    /**
     * Base44 `typ` → V2 `service_type`. A type absent here is archived in
     * legacy_import_map instead of being given a wrong meaning.
     */
    'service_types' => [
        'LEASINGRUECKGABE' => 'leasingrueckgabe',
        'UEBERFUEHRUNG' => 'ueberfuehrung',
        'GUTACHTEN' => 'gutachten',
        'UNFALLSCHADEN' => 'unfallschaden',
    ],

    /** Base44 `Auftrag.status` is authoritative for the final V2 status. */
    'order_status' => [
        'Abgeschlossen' => 'completed',
        'Storniert' => 'cancelled',
        'Neu eingegangen' => 'order_requested',
    ],

    /** "In Bearbeitung" is resolved through `tracking_status`. */
    'in_progress_status' => [
        'Auftrag eingegangen' => 'order_placed',
        'Auftrag terminiert' => 'confirmed',
        'Abholung terminiert' => 'confirmed',
        'Termin bestätigt' => 'confirmed',
        'Angebotsfreigabe' => 'inspected',
        'In Reparatur' => 'workshop',
        'Rückgabe Autohaus' => 'vehicle_returned',
        'Gutachten terminiert' => 'confirmed',
    ],

    'in_progress_fallback' => 'order_placed',

    /**
     * The statuses the short-path services (Überführung, Gutachten,
     * Unfallschaden) may hold. A mapped status outside the service's set is
     * pulled back to `short_path_fallback`.
     */
    'short_path_statuses' => [
        'ueberfuehrung' => ['order_requested', 'order_placed', 'confirmed', 'vehicle_collected', 'vehicle_returned', 'invoice_processed', 'completed', 'cancelled', 'discarded'],
        'gutachten' => ['order_requested', 'order_placed', 'confirmed', 'completed', 'cancelled', 'discarded'],
        'unfallschaden' => ['order_requested', 'order_placed', 'confirmed', 'completed', 'cancelled', 'discarded'],
    ],

    'short_path_fallback' => 'confirmed',

    /**
     * History labels → V2 status. `null` = no equivalent in V2: the label is
     * kept in the order's legacy metadata but produces no history row.
     */
    'history_status' => [
        'Neu eingegangen' => 'order_requested',
        'Auftrag eingegangen' => 'order_placed',
        'Auftrag terminiert' => 'confirmed',
        'Abholung terminiert' => 'confirmed',
        'Termin bestätigt' => 'confirmed',
        'Angebotsfreigabe' => 'inspected',
        'In Reparatur' => 'workshop',
        'Rückgabe Autohaus' => 'vehicle_returned',
        'Auftrag abgeschlossen' => 'completed',
        'Abgeschlossen' => 'completed',
        'Storniert' => 'cancelled',
        'In Bearbeitung' => null,
        'Pausiert' => null,
        'Gutachten terminiert' => null,
        'Gutachtenprüfung angefordert' => null,
        'Freigabe Versicherung erhalten' => null,
    ],

    /**
     * Per-service overrides of `history_status`: in a Gutachten or an
     * Unfallschaden "Gutachten terminiert" is the scheduled step.
     */
    'history_status_by_service' => [
        'gutachten' => ['Gutachten terminiert' => 'confirmed'],
        'unfallschaden' => ['Gutachten terminiert' => 'confirmed'],
    ],

    /** Lower-cased spelling → canonical manufacturer. */
    'manufacturer_aliases' => [
        'vw' => 'Volkswagen',
        'vw pkw' => 'Volkswagen',
        'volkswagen' => 'Volkswagen',
        'mercedes' => 'Mercedes-Benz',
        'mercedes benz' => 'Mercedes-Benz',
        'mercedes-benz' => 'Mercedes-Benz',
    ],

    /** Values meaning "unknown" in the free-text lessor column. */
    'lessor_unknown_values' => ['k.a.', 'k.a', 'ka', 'n/a', '-', 'unbekannt'],

    'lessor_aliases' => [
        'vw' => 'Volkswagen Leasing GmbH',
        'vw leasing' => 'Volkswagen Leasing GmbH',
        'vw-leasing' => 'Volkswagen Leasing GmbH',
        'volkswagen leasing' => 'Volkswagen Leasing GmbH',
        'vwfs' => 'Volkswagen Leasing GmbH',
        'ald' => 'ALD AutoLeasing D GmbH',
        'alphabet' => 'Alphabet Fuhrparkmanagement GmbH',
        'athlon' => 'Athlon Germany GmbH',
    ],

    /** Only these hosts are fetched when copying attachments into V2 storage. */
    'document_hosts' => ['base44.app'],

    'document_max_bytes' => 52_428_800,

    'document_timeout_seconds' => 30,

    'document_retries' => 2,

    'document_retry_sleep_ms' => 500,
];
