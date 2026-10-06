<?php

namespace Tests\Feature\LegacyImport;

use App\Support\LegacyImport\ImportOptions;
use App\Support\LegacyImport\ImportReport;
use App\Support\LegacyImport\LegacyExport;
use App\Support\LegacyImport\LegacyImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\LegacyExportFixture;
use Tests\TestCase;

/**
 * Shared scenario for the importer tests: a small, invented Base44 export that
 * exercises every rule (relevance, owners, duplicates, splits, archived types).
 */
abstract class LegacyImportTestCase extends TestCase
{
    use RefreshDatabase;

    protected LegacyExportFixture $export;

    /** @var array<string, string> named ids of the scenario rows */
    protected array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        config(['legacy_import.document_retry_sleep_ms' => 0]);
        $this->export = new LegacyExportFixture;
    }

    protected function tearDown(): void
    {
        $this->export->destroy();

        parent::tearDown();
    }

    /**
     * @param  list<string>|null  $steps
     */
    protected function runImport(?array $steps = null, bool $dryRun = false): ImportReport
    {
        $this->export->write();

        return app(LegacyImporter::class)->run(
            new LegacyExport($this->export->directory, config('legacy_import.files')),
            new ImportOptions($dryRun, (string) Str::uuid(), $steps ?? ImportOptions::STEPS),
        );
    }

    protected function buildScenario(): void
    {
        $e = $this->export;
        $u = fn (array $v) => $e->add('users', $v + ['status' => 'active', 'role' => 'user']);

        $this->ids['alpha'] = $e->add('kunde', ['firmenname' => 'Testfirma Alpha GmbH', 'kontaktperson_name' => 'Anna Beispiel', 'kontaktperson_email' => 'kontakt@alpha.example', 'kontaktperson_telefon' => "'+4930123456", 'rechnungsadresse_strasse' => 'Musterstraße 12', 'rechnungsadresse_plz' => '12345', 'rechnungsadresse_ort' => 'Berlin', 'rechnungsadresse_land' => 'Deutschland', 'kundennummer' => 'K-1', 'aktiv' => true, 'gespeicherte_rechnungsadressen' => [['name' => 'Alpha Rechnung', 'strasse' => 'Weg 1', 'plz' => '10115', 'ort' => 'Berlin', 'land' => 'Deutschland']], 'gespeicherte_kostenstellen' => [['name' => 'IT', 'nummer' => '100']]]);
        $this->ids['beta'] = $e->add('kunde', ['firmenname' => 'Testfirma Beta GmbH', 'aktiv' => true]);
        $this->ids['leer'] = $e->add('kunde', ['firmenname' => 'Testfirma Leer GmbH', 'aktiv' => true]);
        $this->ids['delta'] = $e->add('kunde', ['firmenname' => 'Testfirma Delta GmbH', 'aktiv' => false, 'kontaktperson_email' => 'nobody@delta.example']);
        $this->ids['gamma'] = $e->add('kunde', ['firmenname' => 'Testfirma Gamma GmbH', 'aktiv' => true, 'kontaktperson_email' => 'zweite@gamma.example']);

        $u(['email' => 'admin@alpha.example', 'role' => 'admin', 'kunde_id' => $this->ids['alpha'], 'anzeigename' => 'Alpha Admin', 'created_date' => '2026-01-10T10:00:00.000000']);
        $u(['email' => 'user@alpha.example', 'kunde_id' => $this->ids['alpha'], 'full_name' => 'Uwe Alpha', 'created_date' => '2026-01-05T10:00:00.000000']);
        $u(['email' => 'erste@delta.example', 'kunde_id' => $this->ids['delta'], 'created_date' => '2026-02-01T10:00:00.000000']);
        $u(['email' => 'spaeter@delta.example', 'kunde_id' => $this->ids['delta'], 'created_date' => '2026-03-01T10:00:00.000000']);
        $u(['email' => 'frueh@gamma.example', 'kunde_id' => $this->ids['gamma'], 'created_date' => '2026-01-01T10:00:00.000000']);
        $u(['email' => 'zweite@gamma.example', 'kunde_id' => $this->ids['gamma'], 'created_date' => '2026-02-01T10:00:00.000000']);
        $u(['email' => 'staff@leasyback.com', 'role' => 'admin', 'kunde_id' => '']);
        $u(['email' => 'einsam@example.org', 'kunde_id' => '']);
        $u(['email' => 'eingeladen@alpha.example', 'status' => 'invited', 'kunde_id' => '']);

        $car = fn (string $kunde, string $plate, array $v = []) => $e->add('fahrzeug', $v + ['kunde_id' => $kunde, 'kennzeichen' => $plate, 'hersteller' => 'Renault', 'modell' => 'Clio', 'fahrgestellnummer_vin' => 'VF1ABCDEFGH123456', 'created_date' => '2026-02-01T10:00:00.000000']);

        $this->ids['v1'] = $car($this->ids['alpha'], 'b-ab  1234', ['hersteller' => 'RENAULT', 'kilometerstand' => '45000', 'erstzulassung' => '2023-04-01', 'rueckgabedatum' => '2026-12-31T00:00:00.000Z', 'leasinggeber' => 'VWFS', 'kraftstoffart' => 'Diesel', 'notizen' => 'Lack zerkratzt', 'interne_fahrzeugnummer' => 'INT-7']);
        $this->ids['v2'] = $car($this->ids['alpha'], 'B-CD 5678', ['hersteller' => 'VW', 'leasinggeber' => 'k.a.', 'fahrgestellnummer_vin' => 'WVWSHORT123456']);
        $this->ids['v3'] = $car($this->ids['alpha'], 'B-EF 1111');
        $this->ids['v4'] = $car($this->ids['beta'], 'M-XY 99', ['hersteller' => 'DUMMY']);
        $this->ids['dummy_free'] = $car($this->ids['alpha'], 'B-DU 0001', ['hersteller' => 'DUMMY']);
        $this->ids['orphan'] = $car('doesnotexist00000000000', 'B-OR 0001');
        $this->ids['v5'] = $car($this->ids['alpha'], 'B-GH 2222');

        $order = fn (array $v) => $e->add('auftrag', $v + ['kunde_id' => $this->ids['alpha'], 'created_date' => '2026-04-10T08:30:00.000000', 'angelegt_von_nutzer_id' => '', 'created_by' => 'user@alpha.example']);

        $this->ids['o_return'] = $order(['typ' => 'LEASINGRUECKGABE', 'status' => 'Abgeschlossen', 'tracking_status' => 'Rückgabe Autohaus', 'fahrzeug_ids' => [$this->ids['v1']], 'wunschtermin' => '2026-04-20', 'zeitfenster_von' => '08:00', 'zeitfenster_bis' => '10:00', 'fahrzeugstandort_strasse' => 'Hofweg 4', 'fahrzeugstandort_plz' => '10115', 'fahrzeugstandort_ort' => 'Berlin', 'rueckgabeort_name' => 'Autohaus Muster', 'rueckgabeort_strasse' => 'Ring 9a', 'rueckgabeort_plz' => '20095', 'rueckgabeort_ort' => 'Hamburg', 'kostenstelle_name' => 'Fuhrpark', 'kostenstelle_nummer' => '4711', 'rechnungsadresse_name' => 'Alpha Rechnung', 'rechnungsadresse_strasse' => 'Weg 1', 'rechnungsadresse_plz' => '10115', 'rechnungsadresse_ort' => 'Berlin', 'bemerkungen' => 'Bitte vorher anrufen', 'tracking_status_dates' => ['Auftrag eingegangen' => '2026-04-10'], 'ist_testauftrag' => false]);
        $this->ids['o_reloc'] = $order(['typ' => 'UEBERFUEHRUNG', 'status' => 'In Bearbeitung', 'tracking_status' => 'In Reparatur', 'fahrzeug_ids' => [$this->ids['v2'], $this->ids['v3']], 'abholadresse_strasse' => 'Start Allee 5', 'abholadresse_plz' => '50667', 'abholadresse_ort' => 'Köln', 'zieladresse_strasse' => 'Ziel Platz 7', 'zieladresse_plz' => '80331', 'zieladresse_ort' => 'München', 'zeitfenster_von' => '09:00', 'zeitfenster_bis' => '12:00', 'wunschtermin' => '2026-05-02', 'ansprechpartner_ziel_name' => 'Zora Ziel', 'fahrzeug_fahrbereit' => true, 'kostenstelle_name' => 'Logistik', 'kostenstelle_nummer' => '99']);
        $this->ids['o_gutachten'] = $order(['typ' => 'GUTACHTEN', 'status' => 'Abgeschlossen', 'tracking_status' => 'Auftrag abgeschlossen', 'fahrzeug_ids' => [$this->ids['v5']], 'gutachten_standort_ort' => 'Teststadt']);
        $this->ids['o_unknown'] = $order(['typ' => 'SONSTIGES', 'status' => 'Abgeschlossen', 'tracking_status' => 'Auftrag abgeschlossen', 'fahrzeug_ids' => [$this->ids['v5']], 'gutachten_standort_ort' => 'Teststadt']);
        $this->ids['o_dummy'] = $order(['kunde_id' => $this->ids['beta'], 'typ' => 'LEASINGRUECKGABE', 'status' => 'Neu eingegangen', 'tracking_status' => 'Auftrag eingegangen', 'fahrzeug_ids' => [$this->ids['v4']]]);
        $this->ids['o_conflict'] = $order(['kunde_id' => $this->ids['beta'], 'typ' => 'LEASINGRUECKGABE', 'status' => 'In Bearbeitung', 'tracking_status' => 'Abholung terminiert', 'fahrzeug_ids' => [$this->ids['v4']], 'created_date' => '2026-04-11T08:30:00.000000']);
        $this->ids['o_novehicle'] = $order(['typ' => 'LEASINGRUECKGABE', 'status' => 'Abgeschlossen', 'fahrzeug_ids' => '[]']);
        $this->ids['o_cancel'] = $order(['typ' => 'LEASINGRUECKGABE', 'status' => 'Storniert', 'tracking_status' => 'Auftrag eingegangen', 'fahrzeug_ids' => [$this->ids['v5']], 'created_date' => '2026-06-01T08:30:00.000000']);

        $h = fn (string $order, string $new, ?string $old, string $at, array $v = []) => $e->add('historie', $v + ['auftrag_id' => $order, 'neuer_status' => $new, 'alter_status' => (string) $old, 'created_date' => $at, 'geaendert_von_email' => 'staff@leasyback.com']);
        $h($this->ids['o_return'], 'Neu eingegangen', null, '2026-04-10T08:30:00.000000');
        $h($this->ids['o_return'], 'Auftrag eingegangen', 'Neu eingegangen', '2026-04-10T09:00:00.000000');
        $h($this->ids['o_return'], 'Pausiert', 'Auftrag eingegangen', '2026-04-11T09:00:00.000000');
        $h($this->ids['o_return'], 'Abholung terminiert', 'Pausiert', '2026-04-12T09:00:00.000000');
        $h($this->ids['o_return'], 'Termin bestätigt', 'Abholung terminiert', '2026-04-13T09:00:00.000000');
        $h($this->ids['o_return'], 'Auftrag abgeschlossen', 'Termin bestätigt', '2026-04-25T09:00:00.000000', ['kommentar' => 'Alles erledigt']);
        $h($this->ids['o_gutachten'], 'Gutachten terminiert', 'Neu eingegangen', '2026-04-12T09:00:00.000000');
        $h($this->ids['o_unknown'], 'Gutachten terminiert', 'Neu eingegangen', '2026-04-12T09:00:00.000000');

        $note = fn (string $order, string $by, string $text, array $v = []) => $e->add('kommentar', $v + ['auftrag_id' => $order, 'erstellt_von_email' => $by, 'text' => $text, 'created_date' => '2026-04-12T10:00:00.000000']);
        $this->ids['c_staff'] = $note($this->ids['o_return'], 'staff@leasyback.com', 'Wir holen morgen ab.');
        $this->ids['c_customer'] = $note($this->ids['o_return'], 'user@alpha.example', 'Danke!', ['geloescht' => false]);
        $this->ids['c_deleted'] = $note($this->ids['o_return'], 'user@alpha.example', 'Falsche Nachricht', ['geloescht' => true]);
        $this->ids['c_files'] = $note($this->ids['o_return'], 'user@alpha.example', 'Anbei die Unterlagen', ['anhaenge' => [['name' => 'Vertrag.pdf', 'url' => 'https://base44.app/api/apps/x/files/public/x/vertrag.pdf'], ['name' => 'Foto.jpg', 'url' => 'https://base44.app/api/apps/x/files/public/x/foto.jpg']]]);
        $this->ids['c_archived_parent'] = $note($this->ids['o_unknown'], 'staff@leasyback.com', 'Gutachtertermin steht');

        $this->ids['d_file'] = $e->add('dateianhang', ['auftrag_id' => $this->ids['o_return'], 'speicherort' => 'https://base44.app/api/apps/x/files/public/x/vertrag.pdf', 'dateiname' => 'Vertrag.pdf', 'dateityp' => 'application/pdf', 'dateigroesse' => '11', 'hochgeladen_von_nutzer_id' => '']);
        $this->ids['d_pseudo'] = $e->add('dateianhang', ['auftrag_id' => 'FAHRZEUG_IMPORT_1768212572926', 'speicherort' => 'https://base44.app/api/apps/x/files/public/x/import.csv', 'dateiname' => 'import.csv', 'dateityp' => 'text/csv', 'dateigroesse' => '3']);

        $this->ids['lead'] = $e->add('lead', ['contact_last_name' => 'Interessent', 'contact_email' => 'lead@example.org', 'status' => 'neu']);
        $this->ids['einladung'] = $e->add('einladung', ['kunde_id' => $this->ids['alpha'], 'email' => 'eingeladen@alpha.example', 'role' => 'user']);
        $this->ids['notification'] = $e->add('benachrichtigung', ['auftrag_id' => $this->ids['o_return'], 'titel' => 'Neue Nachricht', 'nutzer_email' => 'user@alpha.example']);
    }
}
