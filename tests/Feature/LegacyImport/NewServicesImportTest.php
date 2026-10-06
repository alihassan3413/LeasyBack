<?php

namespace Tests\Feature\LegacyImport;

use App\Models\LegacyImportMap;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/** The mapping onto the Gutachten / Unfallschaden services and their tables. */
class NewServicesImportTest extends LegacyImportTestCase
{
    private function legacy(string $entity, string $id): LegacyImportMap
    {
        return LegacyImportMap::query()->where('entity', $entity)->where('legacy_id', $id)->firstOrFail();
    }

    private function order(string $auftragId, string $entity = 'auftrag'): object
    {
        return DB::table('leasyback_orders')->where('id', $this->legacy($entity, $auftragId)->target_id)->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(object $order): array
    {
        return json_decode($order->request_payload, true);
    }

    /** The base scenario plus the rows only the new services need. */
    private function buildNewServicesScenario(): void
    {
        $this->buildScenario();
        $e = $this->export;

        // The scenario's Gutachten order gets the fields a Gutachten carries.
        $this->ids['v6'] = $e->add('fahrzeug', ['kunde_id' => $this->ids['alpha'], 'kennzeichen' => 'B-IJ 3333', 'hersteller' => 'Skoda', 'modell' => 'Octavia', 'fahrgestellnummer_vin' => 'TMBABCDEFGH123456', 'created_date' => '2026-02-01T10:00:00.000000']);
        $this->ids['v7'] = $e->add('fahrzeug', ['kunde_id' => $this->ids['alpha'], 'kennzeichen' => 'B-KL 4444', 'hersteller' => 'Skoda', 'modell' => 'Fabia', 'fahrgestellnummer_vin' => 'TMBABCDEFGH765432', 'created_date' => '2026-02-01T10:00:00.000000']);
        $this->ids['v8'] = $e->add('fahrzeug', ['kunde_id' => $this->ids['alpha'], 'kennzeichen' => 'B-MN 5555', 'hersteller' => 'Seat', 'modell' => 'Leon', 'fahrgestellnummer_vin' => 'VSSABCDEFGH123456', 'created_date' => '2026-02-01T10:00:00.000000']);

        $order = fn (array $v) => $e->add('auftrag', $v + ['kunde_id' => $this->ids['alpha'], 'created_date' => '2026-05-10T08:30:00.000000', 'created_by' => 'user@alpha.example', 'rechnungsadresse_name' => 'Alpha Rechnung', 'rechnungsadresse_strasse' => 'Weg 1', 'rechnungsadresse_plz' => '10115', 'rechnungsadresse_ort' => 'Berlin', 'rechnungsadresse_land' => 'Deutschland']);

        $this->ids['o_multi'] = $order(['typ' => 'GUTACHTEN', 'status' => 'In Bearbeitung', 'tracking_status' => 'Gutachten terminiert', 'fahrzeug_ids' => [$this->ids['v6'], $this->ids['v7']], 'wunschtermin' => '2026-06-01', 'zeitfenster_von' => '08:00', 'zeitfenster_bis' => '12:00', 'fahrzeugstandort_strasse' => 'Werkstatt Str. 3', 'fahrzeugstandort_plz' => '10115', 'fahrzeugstandort_ort' => 'Berlin', 'gutachten_abholung_gewuenscht' => true, 'gutachten_rueckfuehrung_gewuenscht' => true, 'gutachten_pruefstelle_name' => 'Prüfstelle Nord', 'gutachten_pruefstelle_strasse' => 'Prüfweg 2', 'gutachten_pruefstelle_plz' => '20095', 'gutachten_pruefstelle_ort' => 'Hamburg', 'leasinggeber_auftrag' => 'Beispiel Leasing', 'kostenstelle_name' => 'Fuhrpark', 'kostenstelle_nummer' => '4711', 'ansprechpartner_vor_ort_name' => 'Vera Vorort', 'bemerkungen' => 'Zwei Fahrzeuge']);
        $this->ids['o_accident'] = $order(['typ' => 'UNFALLSCHADEN', 'status' => 'Abgeschlossen', 'tracking_status' => 'Freigabe Versicherung erhalten', 'fahrzeug_ids' => [$this->ids['v8']], 'fahrzeugstandort_strasse' => 'Unfallweg 8', 'fahrzeugstandort_plz' => '50667', 'fahrzeugstandort_ort' => 'Köln', 'rueckfuehrort_abweichend' => true, 'rueckfuehrort_strasse' => 'Rückweg 1', 'rueckfuehrort_plz' => '80331', 'rueckfuehrort_ort' => 'München', 'ansprechpartner_ziel_name' => 'Rita Rück', 'unfall_datum' => '2026-05-01', 'unfallhergang' => 'Auffahrunfall', 'polizei_eingeschaltet' => true, 'bemerkungen' => 'Bitte schnell']);

        $e->add('historie', ['auftrag_id' => $this->ids['o_multi'], 'neuer_status' => 'Gutachten terminiert', 'alter_status' => 'Neu eingegangen', 'created_date' => '2026-05-11T09:00:00.000000', 'geaendert_von_email' => 'staff@leasyback.com']);
        $e->add('historie', ['auftrag_id' => $this->ids['o_accident'], 'neuer_status' => 'Freigabe Versicherung erhalten', 'alter_status' => 'Neu eingegangen', 'created_date' => '2026-05-11T09:00:00.000000']);
        $e->add('historie', ['auftrag_id' => $this->ids['o_accident'], 'neuer_status' => 'Abgeschlossen', 'alter_status' => 'Neu eingegangen', 'created_date' => '2026-05-20T09:00:00.000000']);

        // files: staff upload on the accident order, customer upload on the multi order, and one on a leasing return
        $this->ids['d_staff'] = $e->add('dateianhang', ['auftrag_id' => $this->ids['o_accident'], 'speicherort' => 'https://base44.app/api/apps/x/files/public/x/abschluss.pdf', 'dateiname' => 'Abschluss.pdf', 'dateityp' => 'application/pdf', 'dateigroesse' => '4', 'hochgeladen_von_nutzer_id' => 'aaaaaaaaaaaaaaaaaaaaaaaa', 'created_by_id' => 'aaaaaaaaaaaaaaaaaaaaaaaa', 'created_by' => 'staff@leasyback.com']);
        $this->ids['d_customer'] = $e->add('dateianhang', ['auftrag_id' => $this->ids['o_multi'], 'speicherort' => 'https://base44.app/api/apps/x/files/public/x/fahrzeugschein.jpg', 'dateiname' => 'Fahrzeugschein.jpg', 'dateityp' => 'image/jpeg', 'dateigroesse' => '4', 'hochgeladen_von_nutzer_id' => 'bbbbbbbbbbbbbbbbbbbbbbbb', 'created_by_id' => 'bbbbbbbbbbbbbbbbbbbbbbbb', 'created_by' => 'user@alpha.example']);
        $this->ids['c_accident_files'] = $e->add('kommentar', ['auftrag_id' => $this->ids['o_accident'], 'erstellt_von_email' => 'user@alpha.example', 'text' => 'Fotos anbei', 'anhaenge' => [['name' => 'Schaden.png', 'url' => 'https://base44.app/api/apps/x/files/public/x/schaden.png']], 'created_date' => '2026-05-12T10:00:00.000000']);
    }

    private function fakeFiles(): void
    {
        Http::fake(['base44.app/*' => Http::response('DATA', 200)]);
    }

    public function test_a_gutachten_migrates_as_a_gutachten_with_the_stored_shape(): void
    {
        $this->buildNewServicesScenario();
        $this->runImport(['companies', 'users', 'vehicles', 'orders']);

        $order = $this->order($this->ids['o_gutachten']);
        $payload = $this->payload($order);

        $this->assertSame(['gutachten', 'completed', 'leasyback'], [$order->service_type, $order->order_status, $order->leasyback_partner]);
        $this->assertNull($order->active_vehicle_id);
        $this->assertSame('vehicle_appraisal', $payload['order_type']);
        $this->assertSame('Teststadt', $payload['vehicle_location']['city']);
        $this->assertFalse($payload['pickup_requested']);
        $this->assertFalse($payload['return_transport']);
        $this->assertCount(1, $payload['vehicles']);
        $this->assertSame('B-GH 2222', $payload['vehicles'][0]['license_plate']);
        $this->assertSame('GUTACHTEN', $payload['legacy']['typ']);
        $this->assertSame(1, DB::table('leasyback_order_vehicles')->where('order_id', $order->id)->count());
        $this->assertSame(0, DB::table('leasyback_order_vehicles')->where('order_id', $order->id)->whereNotNull('active_vehicle_id')->count(), 'a closed order holds no vehicle slot');
    }

    public function test_a_multi_vehicle_gutachten_is_one_order_with_every_vehicle_linked(): void
    {
        $this->buildNewServicesScenario();
        $report = $this->runImport(['companies', 'users', 'vehicles', 'orders']);

        $order = $this->order($this->ids['o_multi']);
        $payload = $this->payload($order);

        $this->assertSame(['gutachten', 'confirmed'], [$order->service_type, $order->order_status]);
        $this->assertNotNull($order->active_vehicle_id);

        $links = DB::table('leasyback_order_vehicles')->where('order_id', $order->id)->orderBy('position')->get();
        $this->assertSame([0, 1], $links->pluck('position')->map(fn ($p) => (int) $p)->all());
        $this->assertSame($order->vehicle_id, $links[0]->vehicle_id, 'the first vehicle is the one on the order row');
        $this->assertSame($links->pluck('vehicle_id')->all(), $links->pluck('active_vehicle_id')->all(), 'an open order holds a slot per vehicle');
        $this->assertSame(['B-IJ 3333', 'B-KL 4444'], array_column($payload['vehicles'], 'license_plate'));

        $this->assertTrue($payload['pickup_requested']);
        $this->assertTrue($payload['return_transport']);
        $this->assertSame('08:00-12:00', $payload['time_slot']);
        $this->assertSame('Beispiel Leasing', $payload['leasing_company']);
        $this->assertSame('Werkstatt Str.', $payload['vehicle_location']['street']);
        $this->assertSame('Vera Vorort', $payload['location_contact']['name']);
        $this->assertSame(['name' => 'Alpha Rechnung', 'street' => 'Weg', 'number' => '1', 'zip_code' => '10115', 'city' => 'Berlin', 'country' => 'Deutschland'], $payload['billing_address']);
        $this->assertSame(['name' => 'Fuhrpark', 'number' => '4711'], $payload['cost_centre']);

        $logistics = DB::table('leasyback_order_logistics')->where('auftragsnummer', $order->auftragsnummer)->first();
        $this->assertSame(['Prüfstelle Nord', 'Prüfweg 2, 20095 Hamburg'], [$logistics->inspection_site_name, $logistics->inspection_site_address]);
        $this->assertSame(0, (int) $logistics->transport_confirmed);

        $this->assertSame(1, $report->has('auftrag', 'warning', 'multi_vehicle_order'));
        $this->assertSame(2, $report->has('auftrag', 'warning', 'split_one_order_per_vehicle'), 'only the two-vehicle relocation is split');
    }

    public function test_other_multi_vehicle_services_are_still_one_order_per_vehicle(): void
    {
        $this->buildNewServicesScenario();
        $this->runImport(['companies', 'users', 'vehicles', 'orders']);

        $this->assertSame('imported', $this->legacy('auftrag_split', $this->ids['o_reloc'].'#2')->status);
        $this->assertSame(0, DB::table('leasyback_order_vehicles')->whereIn('order_id', [$this->legacy('auftrag', $this->ids['o_reloc'])->target_id, $this->legacy('auftrag_split', $this->ids['o_reloc'].'#2')->target_id])->count());
    }

    public function test_a_later_open_order_is_refused_when_a_vehicle_already_sits_in_an_open_gutachten(): void
    {
        $this->buildNewServicesScenario();
        $second = $this->export->add('auftrag', ['kunde_id' => $this->ids['alpha'], 'typ' => 'GUTACHTEN', 'status' => 'Neu eingegangen', 'fahrzeug_ids' => [$this->ids['v7']], 'created_date' => '2026-06-02T08:30:00.000000']);
        $relocation = $this->export->add('auftrag', ['kunde_id' => $this->ids['alpha'], 'typ' => 'UEBERFUEHRUNG', 'status' => 'Neu eingegangen', 'fahrzeug_ids' => [$this->ids['v6']], 'created_date' => '2026-06-03T08:30:00.000000']);

        $report = $this->runImport(['companies', 'users', 'vehicles', 'orders']);

        $this->assertSame('imported', $this->legacy('auftrag', $this->ids['o_multi'])->status);

        foreach ([$second, $relocation] as $refused) {
            $this->assertSame('skipped', $this->legacy('auftrag', $refused)->status);
            $this->assertSame('vehicle_has_open_order', $this->legacy('auftrag', $refused)->payload['reason']);
        }

        $this->assertSame(2, DB::table('leasyback_order_vehicles')->where('order_id', $this->legacy('auftrag', $this->ids['o_multi'])->target_id)->count());
        $this->assertSame(3, $report->has('auftrag', 'skipped', 'vehicle_has_open_order'), 'the base scenario already has one clash');
    }

    public function test_an_unfallschaden_migrates_as_an_unfallschaden(): void
    {
        $this->buildNewServicesScenario();
        $this->runImport(['companies', 'users', 'vehicles', 'orders']);

        $order = $this->order($this->ids['o_accident']);
        $payload = $this->payload($order);

        $this->assertSame(['unfallschaden', 'completed'], [$order->service_type, $order->order_status]);
        $this->assertSame('accident_damage', $payload['order_type']);
        $this->assertSame('Unfallweg', $payload['vehicle_location']['street']);
        $this->assertTrue($payload['return_differs']);
        $this->assertSame('München', $payload['return_address']['city']);
        $this->assertSame('Rita Rück', $payload['return_contact']['name']);
        $this->assertSame('Alpha Rechnung', $payload['billing_address']['name']);
        $this->assertStringContainsString('Bitte schnell', $payload['notes']);
        $this->assertStringContainsString('Unfallhergang: Auffahrunfall', $payload['notes']);
        $this->assertSame('Auffahrunfall', $payload['legacy']['gutachten_unfall']['unfallhergang']);
        $this->assertSame(0, DB::table('leasyback_order_vehicles')->where('order_id', $order->id)->count(), 'an Unfallschaden is single-vehicle');

        $logistics = DB::table('leasyback_order_logistics')->where('auftragsnummer', $order->auftragsnummer)->first();
        $this->assertSame('Köln', json_decode($logistics->pickup_details, true)['city']);
        $this->assertSame('München', json_decode($logistics->delivery_details, true)['city']);
    }

    public function test_history_labels_of_the_new_services_follow_the_short_path(): void
    {
        $this->buildNewServicesScenario();
        $report = $this->runImport(['companies', 'users', 'vehicles', 'orders', 'history']);

        $statuses = fn (string $legacyOrder) => DB::table('leasyback_order_status_updates')
            ->where('auftragsnummer', $this->legacy('auftrag', $legacyOrder)->payload['auftragsnummer'])
            ->orderBy('created_at')->pluck('new_status')->all();

        $this->assertSame(['confirmed'], $statuses($this->ids['o_multi']), '"Gutachten terminiert" is the Gutachten\'s scheduled step');
        $this->assertSame(['confirmed'], $statuses($this->ids['o_gutachten']));
        $this->assertSame(['completed'], $statuses($this->ids['o_accident']), '"Freigabe Versicherung erhalten" has no V2 status and stays in legacy metadata');
        $this->assertSame(2, $report->has('historie', 'skipped', 'no_v2_equivalent'), 'Pausiert and Freigabe Versicherung erhalten');
    }

    public function test_saved_billing_addresses_and_cost_centres_migrate_into_the_company_books(): void
    {
        $this->buildNewServicesScenario();
        $this->runImport(['companies', 'books']);

        $b2bId = $this->legacy('kunde', $this->ids['alpha'])->target_id;
        $addresses = DB::table('company_billing_addresses')->where('b2b_id', $b2bId)->get();
        $this->assertCount(1, $addresses);
        $this->assertSame('Alpha Rechnung', $addresses[0]->name);
        $this->assertSame(1, (int) $addresses[0]->is_default);
        $this->assertSame(['street' => 'Weg', 'number' => '1', 'zip_code' => '10115', 'city' => 'Berlin', 'country' => 'Deutschland'], json_decode($addresses[0]->details, true));

        $centre = DB::table('company_cost_centres')->where('b2b_id', $b2bId)->first();
        $this->assertSame(['IT', '100'], [$centre->name, $centre->number]);
    }

    public function test_book_entries_are_deduplicated_and_only_the_first_address_is_the_default(): void
    {
        $kunde = $this->export->add('kunde', ['firmenname' => 'Bücher GmbH', 'aktiv' => true, 'gespeicherte_rechnungsadressen' => [
            ['name' => 'Erste', 'strasse' => 'Eins 1', 'plz' => '10115', 'ort' => 'Berlin', 'land' => 'Deutschland'],
            ['name' => 'Erste', 'strasse' => 'Eins 1', 'plz' => '10115', 'ort' => 'Berlin', 'land' => 'Deutschland'],
            ['name' => 'Zweite', 'strasse' => 'Zwei 2', 'plz' => '20095', 'ort' => 'Hamburg', 'land' => 'Deutschland'],
            ['name' => 'Leer', 'strasse' => '', 'plz' => '', 'ort' => '', 'land' => ''],
        ], 'gespeicherte_kostenstellen' => [
            ['name' => 'IT', 'nummer' => '100'], ['name' => 'IT', 'nummer' => '100'], ['name' => '', 'nummer' => '40100610'], ['name' => '', 'nummer' => ''], ['name' => 'Ohne Nummer', 'nummer' => ''],
        ]]);
        $this->export->add('users', ['email' => 'buch@example.org', 'role' => 'user', 'status' => 'active', 'kunde_id' => $kunde]);

        $report = $this->runImport(['companies', 'books']);
        $b2bId = $this->legacy('kunde', $kunde)->target_id;

        $addresses = DB::table('company_billing_addresses')->where('b2b_id', $b2bId)->orderBy('name')->get();
        $this->assertSame(['Erste', 'Zweite'], $addresses->pluck('name')->all());
        $this->assertSame([1, 0], $addresses->pluck('is_default')->map(fn ($d) => (int) $d)->all());
        $this->assertSame(1, $report->has('billing_address', 'deduplicated'));
        $this->assertSame(1, $report->has('billing_address', 'skipped', 'empty_entry'));

        $centres = DB::table('company_cost_centres')->where('b2b_id', $b2bId)->orderBy('name')->get(['name', 'number']);
        $this->assertSame([['40100610', '40100610'], ['IT', '100'], ['Ohne Nummer', null]], $centres->map(fn ($c) => [$c->name, $c->number])->all());
        $this->assertSame(1, $report->has('cost_centre', 'deduplicated'));
        $this->assertSame(1, $report->has('cost_centre', 'skipped', 'empty_entry'));
        $this->assertSame(1, $report->has('cost_centre', 'imported', 'number_used_as_name'));
    }

    public function test_a_users_own_saved_lists_are_archived_not_merged_into_the_company(): void
    {
        $kunde = $this->export->add('kunde', ['firmenname' => 'Einzel GmbH', 'aktiv' => true]);
        $this->export->add('users', ['email' => 'einzel@example.org', 'role' => 'user', 'status' => 'active', 'kunde_id' => $kunde, 'gespeicherte_rechnungsadressen' => [['name' => 'Privat', 'strasse' => 'Weg 1', 'plz' => '10115', 'ort' => 'Berlin', 'land' => 'Deutschland']]]);

        $this->runImport(['companies', 'books', 'users']);

        $this->assertSame(0, DB::table('company_billing_addresses')->count());
        $this->assertSame('Privat', $this->legacy('user', 'einzel@example.org')->payload['archived_billing_addresses'][0]['name']);
    }

    public function test_attachments_of_the_new_services_use_the_order_attachment_table_and_everything_else_does_not(): void
    {
        $this->buildNewServicesScenario();
        $this->fakeFiles();

        $this->runImport();

        $accident = $this->legacy('auftrag', $this->ids['o_accident']);
        $files = DB::table('leasyback_order_attachments')->where('order_id', $accident->target_id)->orderBy('original_name')->get();

        $this->assertSame(['Abschluss.pdf', 'Schaden.png'], $files->pluck('original_name')->all());
        $this->assertSame(['final_document', 'customer_upload'], $files->pluck('kind')->all(), 'a staff upload is the final document, a customer\'s is a supporting file');
        $this->assertSame('application/pdf', $files[0]->mime_type);
        $this->assertSame(4, (int) $files[0]->size);
        $this->assertSame($accident->payload['auftragsnummer'], $files[0]->auftragsnummer);
        $this->assertMatchesRegularExpression('#^accident-damage/'.$accident->target_id.'/final/[0-9a-f-]{36}\.pdf$#', $files[0]->path);
        $this->assertMatchesRegularExpression('#^accident-damage/'.$accident->target_id.'/[0-9a-f-]{36}\.png$#', $files[1]->path);
        Storage::disk('documents')->assertExists($files[0]->path);

        $multi = $this->legacy('auftrag', $this->ids['o_multi']);
        $appraisal = DB::table('leasyback_order_attachments')->where('order_id', $multi->target_id)->first();
        $this->assertSame('customer_upload', $appraisal->kind);
        $this->assertStringStartsWith('appraisal/'.$multi->target_id.'/', $appraisal->path);

        $link = $this->legacy('dokument', $this->ids['c_accident_files'].'#1');
        $this->assertSame('leasyback_order_attachments', $link->target_table);
        $this->assertSame($this->ids['c_accident_files'], $link->payload['comment_legacy_id']);
        $this->assertSame($this->legacy('kommentar', $this->ids['c_accident_files'])->target_id, $link->payload['message_id']);

        // a leasing return keeps its files where the portal shows them
        $returnNumber = $this->legacy('auftrag', $this->ids['o_return'])->payload['auftragsnummer'];
        $this->assertSame(3, DB::table('vehicle_report_documents')->where('auftragsnummer', $returnNumber)->count());
        $this->assertSame(0, DB::table('leasyback_order_attachments')->where('auftragsnummer', $returnNumber)->count());
    }

    public function test_a_second_run_creates_nothing_in_the_new_tables_either(): void
    {
        $this->buildNewServicesScenario();
        $this->fakeFiles();
        $this->runImport();

        $snapshot = fn () => [
            DB::table('leasyback_orders')->count(), DB::table('leasyback_order_vehicles')->count(), DB::table('leasyback_order_attachments')->count(),
            DB::table('company_billing_addresses')->count(), DB::table('company_cost_centres')->count(), DB::table('vehicle_report_documents')->count(),
            count(Storage::disk('documents')->allFiles()), LegacyImportMap::query()->count(),
        ];

        $before = $snapshot();
        $this->runImport();

        $this->assertSame($before, $snapshot());
    }

    public function test_reconcile_and_rollback_cover_the_new_tables(): void
    {
        $this->buildNewServicesScenario();
        $this->fakeFiles();
        $this->export->write();
        $reports = sys_get_temp_dir().DIRECTORY_SEPARATOR.'legacy-new-'.uniqid();

        $this->artisan('legacy:import', ['--source' => $this->export->directory, '--report-path' => $reports])->assertSuccessful();
        $batch = (string) LegacyImportMap::query()->orderByDesc('id')->value('batch_id');

        $this->assertGreaterThan(0, DB::table('leasyback_order_attachments')->count());
        $this->artisan('legacy:reconcile', ['--source' => $this->export->directory, '--report-path' => $reports])->assertSuccessful();

        $this->artisan('legacy:rollback', ['batch' => $batch, '--report-path' => $reports])->assertSuccessful();

        foreach (['leasyback_orders', 'leasyback_order_vehicles', 'leasyback_order_attachments', 'company_billing_addresses', 'company_cost_centres', 'vehicle_report_documents', 'legacy_import_map'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} must be empty after rollback");
        }

        $this->assertSame([], Storage::disk('documents')->allFiles());

        foreach (glob($reports.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }

        foreach (glob($reports.DIRECTORY_SEPARATOR.'*') ?: [] as $dir) {
            @rmdir($dir);
        }

        @rmdir($reports);
    }
}
