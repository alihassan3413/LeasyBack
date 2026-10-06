<?php

namespace Tests\Support;

use Illuminate\Support\Str;

/**
 * Builds a synthetic Base44 export (same file names and column names as the
 * real one, entirely invented values) in a temp folder outside the repository.
 * Never copy production rows into a test.
 */
final class LegacyExportFixture
{
    private const COLUMNS = [
        'kunde' => ['kontaktperson_name', 'kundennummer', 'kontaktperson_email', 'gespeicherte_rechnungsadressen', 'rechnungsadresse_plz', 'kontaktperson_telefon', 'rechnungsadresse_strasse', 'rechnungsadresse_land', 'rechnungsadresse_ort', 'firmenname', 'gespeicherte_kostenstellen', 'aktiv', 'id', 'created_date', 'updated_date', 'created_by_id', 'created_by', 'is_sample'],
        'users' => ['email', 'full_name', 'role', 'status', 'created_date', 'updated_date', 'anzeigename', 'kunde_id', 'einladung_offen', 'gespeicherte_rechnungsadressen', 'gespeicherte_kostenstellen'],
        'fahrzeug' => ['hersteller', 'kraftstoffart', 'standort_ort', 'notizen', 'modell', 'kunde_id', 'kilometerstand', 'rueckgabedatum', 'standort_plz', 'kennzeichen', 'erstzulassung', 'abgeschlossen', 'interne_fahrzeugnummer', 'fahrgestellnummer_vin', 'leasinggeber', 'status', 'id', 'created_date', 'updated_date', 'created_by_id', 'created_by', 'is_sample'],
        'auftrag' => ['abholadresse_land', 'tracking_status', 'zeitfenster_von', 'ansprechpartner_ziel_email', 'typ', 'gutachten_pruefstelle_strasse', 'rechnungsadresse_strasse', 'rechnungsadresse_land', 'gutachten_pruefstelle_ort', 'rueckgabeort_name', 'ansprechpartner_abholung_email', 'unfallort_strasse', 'unfall_datum', 'fahrzeug_ids', 'gutachten_pruefstelle_plz', 'kostenstelle_name', 'unfall_uhrzeit', 'polizei_aktenzeichen', 'fahrzeug_fahrbereit', 'zieladresse_plz', 'rueckfuehrort_ort', 'gutachten_standort_strasse', 'zieladresse_strasse', 'polizei_eingeschaltet', 'kostenstelle_nummer', 'fahrzeugstandort_ort', 'rechnungsadresse_name', 'rechnungsadresse_ort', 'rueckgabeort_land', 'zieladresse_ort', 'rueckfuehrort_plz', 'tracking_status_dates', 'rechnungsadresse_plz', 'fahrzeugstandort_plz', 'rueckgabeort_plz', 'status', 'zeitfenster_bis', 'pausiert', 'ansprechpartner_vor_ort_telefon', 'bemerkungen', 'fahrzeugstandort_land', 'abholadresse_plz', 'gutachten_standort_ort', 'rueckgabeort_ort', 'gutachten_abholung_gewuenscht', 'ist_testauftrag', 'rueckfuehrort_abweichend', 'leasinggeber_auftrag', 'ansprechpartner_ziel_name', 'gutachten_standort_plz', 'gutachten_rueckfuehrung_gewuenscht', 'wunschtermin', 'gutachten_selbst_bringen', 'rueckfuehrort_land', 'rueckfuehrort_strasse', 'kunde_id', 'ansprechpartner_ziel_telefon', 'zieladresse_land', 'ansprechpartner_vor_ort_email', 'ansprechpartner_abholung_name', 'unfallort_plz', 'abholadresse_ort', 'schadensbeschreibung', 'abholadresse_strasse', 'unfallhergang', 'angelegt_von_nutzer_id', 'rueckgabeort_strasse', 'fahrzeugstandort_strasse', 'gutachten_pruefstelle_name', 'unfallort_ort', 'ansprechpartner_vor_ort_name', 'ansprechpartner_abholung_telefon', 'id', 'created_date', 'updated_date', 'created_by_id', 'created_by', 'is_sample'],
        'kommentar' => ['auftrag_id', 'anhaenge', 'erstellt_von_nutzer_id', 'kunde_id', 'erstellt_von_email', 'text', 'geloescht', 'id', 'created_date', 'updated_date', 'created_by_id', 'created_by', 'is_sample'],
        'historie' => ['auftrag_id', 'geaendert_von_nutzer_id', 'neuer_status', 'kunde_id', 'geaendert_von_email', 'alter_status', 'kommentar', 'id', 'created_date', 'updated_date', 'created_by_id', 'created_by', 'is_sample'],
        'dateianhang' => ['auftrag_id', 'speicherort', 'dateiname', 'dateityp', 'dateigroesse', 'hochgeladen_von_nutzer_id', 'kunde_id', 'id', 'created_date', 'updated_date', 'created_by_id', 'created_by', 'is_sample'],
        'lead' => ['customer_type', 'contact_phone', 'contact_email', 'contact_last_name', 'contact_first_name', 'status', 'id', 'created_date', 'updated_date', 'created_by_id', 'created_by', 'is_sample'],
        'einladung' => ['kunde_id', 'email', 'role', 'id', 'created_date', 'updated_date', 'created_by_id', 'created_by', 'is_sample'],
        'benachrichtigung' => ['auftrag_id', 'titel', 'nachricht', 'nutzer_id', 'erstellt_von_nutzer_id', 'gelesen', 'typ', 'erstellt_von_email', 'nutzer_email', 'id', 'created_date', 'updated_date', 'created_by_id', 'created_by', 'is_sample'],
    ];

    private const FILES = [
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
    ];

    /** @var array<string, list<array<string, string>>> */
    private array $rows = [];

    private int $counter = 0;

    public readonly string $directory;

    public function __construct()
    {
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'legacy-import-test-'.Str::random(10);
        mkdir($this->directory, 0700, true);

        foreach (array_keys(self::COLUMNS) as $dataset) {
            $this->rows[$dataset] = [];
        }
    }

    /**
     * Add a row; returns its (synthetic) id, or the e-mail for users.
     *
     * @param  array<string, mixed>  $values
     */
    public function add(string $dataset, array $values = []): string
    {
        $row = array_fill_keys(self::COLUMNS[$dataset], '');

        if (isset($row['id'])) {
            $row['id'] = sprintf('%024x', ++$this->counter);
        }

        foreach (['created_date', 'updated_date'] as $column) {
            if (array_key_exists($column, $row)) {
                $row[$column] = '2026-03-01T10:00:00.000000';
            }
        }

        foreach ($values as $column => $value) {
            $row[$column] = is_bool($value) ? ($value ? 'true' : 'false') : (is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value);
        }

        $this->rows[$dataset][] = $row;

        return $dataset === 'users' ? $row['email'] : $row['id'];
    }

    public function write(): self
    {
        foreach (self::COLUMNS as $dataset => $columns) {
            $handle = fopen($this->directory.DIRECTORY_SEPARATOR.self::FILES[$dataset], 'wb');
            fputcsv($handle, $columns, ',', '"', '');

            foreach ($this->rows[$dataset] as $row) {
                fputcsv($handle, array_values($row), ',', '"', '');
            }

            fclose($handle);
        }

        return $this;
    }

    public function destroy(): void
    {
        foreach (glob($this->directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);
    }
}
