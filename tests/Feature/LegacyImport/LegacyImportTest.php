<?php

namespace Tests\Feature\LegacyImport;

use App\Models\LegacyImportMap;
use App\Support\LegacyImport\ImportOptions;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class LegacyImportTest extends LegacyImportTestCase
{
    private function legacy(string $entity, string $id): LegacyImportMap
    {
        return LegacyImportMap::query()->where('entity', $entity)->where('legacy_id', $id)->firstOrFail();
    }

    public function test_only_relevant_companies_are_imported_and_placeholders_are_reported(): void
    {
        $this->buildScenario();
        $report = $this->runImport(['companies']);

        $this->assertSame(4, DB::table('b2b')->count());
        $this->assertSame('skipped', $this->legacy('kunde', $this->ids['leer'])->status);
        $this->assertSame('no_users_vehicles_or_orders', $this->legacy('kunde', $this->ids['leer'])->payload['reason']);

        $this->assertSame('imported', $this->legacy('kunde', $this->ids['beta'])->status);
        // beta, delta and gamma carry neither an address nor a contact name
        $this->assertSame(3, $report->has('kunde', 'warning', 'placeholder_address'));
        $this->assertSame(3, $report->has('kunde', 'warning', 'placeholder_contact'));
        $this->assertSame('Adresse nicht hinterlegt', DB::table('addresses')->where('address_id', $this->legacy('kunde', $this->ids['beta'])->payload['address_id'])->value('street'));

        $alpha = DB::table('b2b')->where('b2b_id', $this->legacy('kunde', $this->ids['alpha'])->target_id)->first();
        $address = DB::table('addresses')->where('address_id', $alpha->address_id)->first();
        $this->assertSame(['Musterstraße', '12', '12345', 'Berlin'], [$address->street, $address->number, $address->zip_code, $address->city]);
        $this->assertSame('Beispiel', DB::table('contacts')->where('contact_id', $alpha->contact_id)->value('last_name'));

        $phone = DB::table('phone_numbers')->where('contact_id', $alpha->contact_id)->first();
        $this->assertSame(['+49', '30123456'], [$phone->international_prefix, $phone->phone_number]);

        $delta = DB::table('b2b')->where('b2b_id', $this->legacy('kunde', $this->ids['delta'])->target_id)->first();
        $this->assertSame(0, (int) $delta->is_active);
    }

    public function test_a_company_known_only_through_a_pending_invitation_is_imported_by_default(): void
    {
        $lonely = $this->export->add('kunde', ['firmenname' => 'Nur Einladung GmbH', 'aktiv' => true]);
        $this->export->add('einladung', ['kunde_id' => $lonely, 'email' => 'jemand@einladung.example', 'role' => 'user']);

        $this->runImport(['companies']);

        $this->assertSame('imported', $this->legacy('kunde', $lonely)->status);
        $this->assertSame('pending_invitation', $this->legacy('kunde', $lonely)->payload['relevance']);
    }

    public function test_that_company_is_excluded_when_the_config_says_so(): void
    {
        config(['legacy_import.company_relevant_when_invited' => false]);
        $lonely = $this->export->add('kunde', ['firmenname' => 'Nur Einladung GmbH', 'aktiv' => true]);
        $this->export->add('einladung', ['kunde_id' => $lonely, 'email' => 'jemand@einladung.example', 'role' => 'user']);

        $this->runImport(['companies']);

        $this->assertSame('skipped', $this->legacy('kunde', $lonely)->status);
    }

    public function test_saved_billing_addresses_and_cost_centres_are_archived_not_dropped(): void
    {
        $this->buildScenario();
        $this->runImport(['companies']);

        $payload = $this->legacy('kunde', $this->ids['alpha'])->payload;

        $this->assertSame('Alpha Rechnung', $payload['archived_billing_addresses'][0]['name']);
        $this->assertSame('100', $payload['archived_cost_centres'][0]['nummer']);
        $this->assertSame('K-1', $payload['kundennummer']);
    }

    public function test_users_are_created_without_usable_passwords_and_without_mail(): void
    {
        $this->buildScenario();
        $report = $this->runImport(['companies', 'users']);

        // admin+user (alpha), 2 delta, 2 gamma
        $this->assertSame(6, DB::table('users')->count());
        $user = DB::table('users')->where('email', 'user@alpha.example')->first();
        $this->assertSame('Firmenkunde', $user->user_type);
        $this->assertSame(1, (int) $user->is_active);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotNull($user->active_b2b_id);
        $this->assertSame('Uwe Alpha', $user->name);
        $this->assertSame('Alpha Admin', DB::table('users')->where('email', 'admin@alpha.example')->value('name'));
        $this->assertFalse(\Hash::check('password', $user->password));

        $this->assertSame(0, DB::table('users')->where('email', 'staff@leasyback.com')->count());
        $this->assertSame(1, $report->has('user', 'review', 'staff_admin_manual_review'));
        $this->assertSame(1, $report->has('user', 'review', 'active_user_without_company_manual_review'));
        $this->assertSame(1, $report->has('user', 'skipped', 'invited_not_activated'));
    }

    public function test_company_owner_rules(): void
    {
        $this->buildScenario();
        $this->runImport(['companies', 'users']);

        $role = fn (string $email) => DB::table('user_b2b')->join('users', 'users.id', '=', 'user_b2b.user_id')->where('users.email', $email)->value('role');

        // Base44 admin bound to a company is its owner; the other user is a member.
        $this->assertSame('owner', $role('admin@alpha.example'));
        $this->assertSame('member', $role('user@alpha.example'));
        // No admin: the user matching the Kunde's contact e-mail owns it, even if not the earliest.
        $this->assertSame('owner', $role('zweite@gamma.example'));
        $this->assertSame('member', $role('frueh@gamma.example'));
        // Neither: the earliest active user.
        $this->assertSame('owner', $role('erste@delta.example'));
        $this->assertSame('member', $role('spaeter@delta.example'));

        $owner = DB::table('user_b2b')->join('users', 'users.id', '=', 'user_b2b.user_id')->where('users.email', 'admin@alpha.example')->first();
        $this->assertContains('members.manage', json_decode($owner->permissions, true));
        $member = DB::table('user_b2b')->join('users', 'users.id', '=', 'user_b2b.user_id')->where('users.email', 'user@alpha.example')->first();
        $this->assertNotContains('members.manage', json_decode($member->permissions, true));
    }

    public function test_an_existing_v2_account_is_never_altered_and_an_admin_is_never_given_a_company(): void
    {
        $this->buildScenario();

        $existingId = DB::table('users')->insertGetId(['name' => 'Bestand', 'email' => 'User@Alpha.Example', 'password' => 'keep-this-hash', 'user_type' => 'Firmenkunde', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('users')->insert(['name' => 'Chef', 'email' => 'erste@delta.example', 'password' => 'admin-hash', 'user_type' => 'Admin', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        $report = $this->runImport(['companies', 'users']);

        $existing = DB::table('users')->where('id', $existingId)->first();
        $this->assertSame(['Bestand', 'keep-this-hash'], [$existing->name, $existing->password]);
        $this->assertSame('linked', $this->legacy('user', 'user@alpha.example')->status);
        $this->assertSame(1, DB::table('user_b2b')->where('user_id', $existingId)->count());

        $this->assertSame(0, DB::table('user_b2b')->join('users', 'users.id', '=', 'user_b2b.user_id')->where('users.user_type', 'Admin')->count());
        $this->assertSame(1, $report->has('user', 'review', 'existing_v2_account_type_admin'));
    }

    public function test_vehicles_are_filtered_normalised_and_deduplicated(): void
    {
        $this->buildScenario();
        $report = $this->runImport(['companies', 'users', 'vehicles']);

        $v1 = DB::table('vehicles')->where('vehicle_id', $this->legacy('fahrzeug', $this->ids['v1'])->target_id)->first();
        $this->assertSame('B-AB 1234', $v1->license_plate);
        $this->assertSame('Renault', $v1->make);
        $this->assertSame(45000, (int) $v1->mileage);
        $this->assertSame('2023-04-01', substr($v1->first_registration_date, 0, 10));
        $this->assertSame('2026-12-31', substr($v1->leasing_end_date, 0, 10));
        $this->assertSame('Volkswagen Leasing GmbH', $v1->leasinggeber);
        $this->assertSame('B2B', $v1->vehicle_belongs);
        $this->assertNull($v1->b2c_user_id);
        $this->assertNotNull($v1->b2b_id);

        $legacy = $this->legacy('fahrzeug', $this->ids['v1'])->payload;
        $this->assertSame('Diesel', $legacy['kraftstoffart']);
        $this->assertSame('INT-7', $legacy['interne_fahrzeugnummer']);
        $this->assertSame('b-ab  1234', $legacy['original_plate']);

        $v2 = DB::table('vehicles')->where('vehicle_id', $this->legacy('fahrzeug', $this->ids['v2'])->target_id)->first();
        $this->assertSame('Volkswagen', $v2->make);
        $this->assertNull($v2->leasinggeber);
        $this->assertNull($v2->vin);
        $this->assertSame('WVWSHORT123456', $this->legacy('fahrzeug', $this->ids['v2'])->payload['original_vin']);
        $this->assertSame(1, $report->has('fahrzeug', 'warning', 'vin_invalid_dropped'));

        $this->assertSame('skipped', $this->legacy('fahrzeug', $this->ids['dummy_free'])->status);
        $this->assertSame('dummy_vehicle_unreferenced', $this->legacy('fahrzeug', $this->ids['dummy_free'])->payload['reason']);
        $this->assertSame('imported', $this->legacy('fahrzeug', $this->ids['v4'])->status, 'a DUMMY vehicle an order uses is kept');
        $this->assertSame('orphan_kunde', $this->legacy('fahrzeug', $this->ids['orphan'])->payload['reason']);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function twoCars(array $a, array $b): array
    {
        $base = ['hersteller' => 'Renault', 'kennzeichen' => 'K-DU 100', 'fahrgestellnummer_vin' => 'VF1ABCDEFGH123456', 'created_date' => '2026-02-01T10:00:00.000000'];

        return [$this->export->add('fahrzeug', $a + $base), $this->export->add('fahrzeug', $b + $base)];
    }

    public function test_duplicate_plates_resolve_by_reference_then_active_company_then_vin_then_recency(): void
    {
        $active = $this->export->add('kunde', ['firmenname' => 'Aktiv GmbH', 'aktiv' => true]);
        $inactive = $this->export->add('kunde', ['firmenname' => 'Inaktiv GmbH', 'aktiv' => false]);

        // 1. an order-referenced record beats an active company
        [$plain, $referenced] = $this->twoCars(['kunde_id' => $active, 'kennzeichen' => 'K-RF 1'], ['kunde_id' => $inactive, 'kennzeichen' => 'K-RF 1']);
        $this->export->add('auftrag', ['kunde_id' => $inactive, 'typ' => 'LEASINGRUECKGABE', 'status' => 'Abgeschlossen', 'fahrzeug_ids' => [$referenced]]);
        // 2. active company beats inactive
        [$activeCar, $inactiveCar] = $this->twoCars(['kunde_id' => $active, 'kennzeichen' => 'K-AC 1'], ['kunde_id' => $inactive, 'kennzeichen' => 'K-AC 1']);
        // 3. valid VIN beats invalid
        [$badVin, $goodVin] = $this->twoCars(['kunde_id' => $active, 'kennzeichen' => 'K-VN 1', 'fahrgestellnummer_vin' => 'SHORT'], ['kunde_id' => $active, 'kennzeichen' => 'K-VN 1']);
        // 4. newest wins; 5. lowest id breaks a full tie
        [$older, $newer] = $this->twoCars(['kunde_id' => $active, 'kennzeichen' => 'K-NW 1'], ['kunde_id' => $active, 'kennzeichen' => 'K-NW 1', 'created_date' => '2026-05-01T10:00:00.000000']);
        [$tieLow, $tieHigh] = $this->twoCars(['kunde_id' => $active, 'kennzeichen' => 'K-TI 1'], ['kunde_id' => $active, 'kennzeichen' => 'K-TI 1']);

        $report = $this->runImport(['companies', 'vehicles']);

        $status = fn (string $id) => $this->legacy('fahrzeug', $id)->status;

        $this->assertSame(['deduplicated', 'imported'], [$status($plain), $status($referenced)]);
        $this->assertSame(['imported', 'deduplicated'], [$status($activeCar), $status($inactiveCar)]);
        $this->assertSame(['deduplicated', 'imported'], [$status($badVin), $status($goodVin)]);
        $this->assertSame(['deduplicated', 'imported'], [$status($older), $status($newer)]);
        $this->assertSame(['imported', 'deduplicated'], [$status($tieLow), $status($tieHigh)]);

        $this->assertSame(5, $report->has('fahrzeug', 'deduplicated', 'duplicate_plate'));
        $this->assertSame(5, DB::table('vehicles')->count());

        // a folded record still resolves to its survivor, so orders keep their vehicle
        $this->assertSame($this->legacy('fahrzeug', $referenced)->target_id, $this->legacy('fahrzeug', $plain)->target_id);
    }

    public function test_orders_follow_the_agreed_status_and_service_rules(): void
    {
        $this->buildScenario();
        $report = $this->runImport(['companies', 'users', 'vehicles', 'orders']);

        $ret = $this->legacy('auftrag', $this->ids['o_return']);
        $order = DB::table('leasyback_orders')->where('id', $ret->target_id)->first();
        $this->assertSame(['completed', 'leasingrueckgabe', 'leasyback'], [$order->order_status, $order->service_type, $order->leasyback_partner]);
        $this->assertNull($order->active_vehicle_id);
        $this->assertSame('BAB1234'.'260410', $order->auftragsnummer);
        $this->assertTrue(DB::table('order_number_reservations')->where('reference', $order->auftragsnummer)->exists());

        $payload = json_decode($order->request_payload, true);
        $this->assertSame('b2b_collection', $payload['order_type']);
        $this->assertSame('Abgeschlossen', $payload['legacy']['status']);
        $this->assertSame('Rückgabe Autohaus', $payload['legacy']['tracking_status']);
        $this->assertSame('4711', $payload['legacy']['kostenstelle']['nummer']);
        $this->assertSame('Alpha Rechnung', $payload['legacy']['rechnungsadresse']['name']);
        $this->assertCount(6, $payload['legacy']['history']);

        $logistics = DB::table('leasyback_order_logistics')->where('auftragsnummer', $order->auftragsnummer)->first();
        $this->assertSame('2026-04-20', substr($logistics->requested_collection_date, 0, 10));
        $this->assertSame('08:00-10:00', $logistics->requested_collection_time_slot);
        $this->assertSame('Hofweg', json_decode($logistics->pickup_details, true)['street']);
        $this->assertSame('9a', json_decode($logistics->delivery_details, true)['number']);
        $this->assertSame('Bitte vorher anrufen', $logistics->pickup_notes);

        // one order per vehicle for a two-vehicle Auftrag, status pulled into the relocation path
        $this->assertSame('imported', $this->legacy('auftrag', $this->ids['o_reloc'])->status);
        $this->assertSame('imported', $this->legacy('auftrag_split', $this->ids['o_reloc'].'#2')->status);
        $relocation = DB::table('leasyback_orders')->where('id', $this->legacy('auftrag', $this->ids['o_reloc'])->target_id)->first();
        $this->assertSame(['ueberfuehrung', 'confirmed'], [$relocation->service_type, $relocation->order_status]);
        $this->assertNotNull($relocation->active_vehicle_id);
        $relocationPayload = json_decode($relocation->request_payload, true);
        $this->assertSame('vehicle_relocation', $relocationPayload['order_type']);
        $this->assertSame('München', $relocationPayload['destination_address']['city']);
        $this->assertSame('Ziel Platz', $relocationPayload['destination_address']['street']);
        $this->assertSame('09:00-12:00', $relocationPayload['time_slot']);
        $this->assertSame('99', $relocationPayload['cost_centre']['number']);
        $this->assertTrue($relocationPayload['vehicle_ready']);
        $this->assertSame(2, $report->has('auftrag', 'warning', 'status_adjusted_for_relocation'), 'once per split order');

        $this->assertSame('cancelled', DB::table('leasyback_orders')->where('id', $this->legacy('auftrag', $this->ids['o_cancel'])->target_id)->value('order_status'));
        $this->assertSame('order_requested', DB::table('leasyback_orders')->where('id', $this->legacy('auftrag', $this->ids['o_dummy'])->target_id)->value('order_status'));

        // return + two relocations + gutachten + cancelled + dummy = 6 V2 orders from 8 Auftrag
        $this->assertSame(6, DB::table('leasyback_orders')->count());
    }

    public function test_a_type_v2_has_no_service_for_is_archived_unchanged_and_never_remapped(): void
    {
        $this->buildScenario();
        $report = $this->runImport(['companies', 'users', 'vehicles', 'orders']);

        $archived = $this->legacy('auftrag', $this->ids['o_unknown']);

        $this->assertSame('archived', $archived->status);
        $this->assertNull($archived->target_id);
        $this->assertSame('service_type_not_supported_in_v2:SONSTIGES', $archived->payload['reason']);
        $this->assertSame('Teststadt', $archived->payload['row']['gutachten_standort_ort']);
        $this->assertSame(1, $report->has('auftrag', 'archived'));
        $this->assertSame('gutachten', DB::table('leasyback_orders')->where('id', $this->legacy('auftrag', $this->ids['o_gutachten'])->target_id)->value('service_type'), 'Gutachten is a V2 service and migrates as one');
    }

    public function test_orders_without_a_vehicle_or_clashing_with_an_open_order_are_skipped_and_reported(): void
    {
        $this->buildScenario();
        $report = $this->runImport(['companies', 'users', 'vehicles', 'orders']);

        $this->assertSame('no_resolvable_vehicle', $this->legacy('auftrag', $this->ids['o_novehicle'])->payload['reason']);
        $this->assertSame('vehicle_has_open_order', $this->legacy('auftrag', $this->ids['o_conflict'])->payload['reason']);
        $this->assertSame(1, $report->has('auftrag', 'skipped', 'no_resolvable_vehicle'));
        $this->assertSame(1, $report->has('auftrag', 'skipped', 'vehicle_has_open_order'));
    }

    public function test_history_is_mapped_collapsed_and_kept_in_legacy_metadata(): void
    {
        $this->buildScenario();
        $report = $this->runImport(['companies', 'users', 'vehicles', 'orders', 'history']);

        $orderNumber = $this->legacy('auftrag', $this->ids['o_return'])->payload['auftragsnummer'];
        $rows = DB::table('leasyback_order_status_updates')->where('auftragsnummer', $orderNumber)->orderBy('created_at')->get();

        $this->assertSame(
            [[null, 'order_requested'], ['order_requested', 'order_placed'], ['order_placed', 'confirmed'], ['confirmed', 'completed']],
            $rows->map(fn ($r) => [$r->old_status, $r->new_status])->all(),
        );
        $this->assertSame('base44_import', $rows[0]->auth_source);

        $this->assertSame(1, $report->has('historie', 'skipped', 'no_v2_equivalent'));
        $this->assertSame(1, $report->has('historie', 'skipped', 'collapsed_same_status'));
        $this->assertSame(1, $report->has('historie', 'skipped', 'parent_order_archived'));
        $this->assertSame(1, DB::table('leasyback_order_status_updates')->where('auftragsnummer', $this->legacy('auftrag', $this->ids['o_gutachten'])->payload['auftragsnummer'])->count());

        $note = DB::table('b2b_order_notes')->where('auftragsnummer', $orderNumber)->first();
        $this->assertSame('internal', $note->visibility);
        $this->assertStringContainsString('Alles erledigt', $note->body);
    }

    public function test_comments_become_messages_with_the_staff_side_marked_and_everything_read(): void
    {
        $this->buildScenario();
        $adminId = DB::table('users')->insertGetId(['name' => 'Staff', 'email' => 'staff@leasyback.com', 'password' => 'x', 'user_type' => 'Admin', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $report = $this->runImport(['companies', 'users', 'vehicles', 'orders', 'messages']);

        $orderId = $this->legacy('auftrag', $this->ids['o_return'])->target_id;
        $messages = DB::table('order_messages')->where('order_id', $orderId)->orderBy('id')->get();

        $this->assertCount(3, $messages, 'staff, customer and the one with attachments; the deleted one is not imported');
        $staff = $messages->firstWhere('body', 'Wir holen morgen ab.');
        $this->assertSame([1, $adminId], [(int) $staff->sender_is_admin, (int) $staff->sender_id], 'an existing V2 admin is linked as the author');
        $customer = $messages->firstWhere('body', 'Danke!');
        $this->assertSame(0, (int) $customer->sender_is_admin);
        $this->assertSame('user@alpha.example', DB::table('users')->where('id', $customer->sender_id)->value('email'));

        $this->assertSame('deleted_in_source', $this->legacy('kommentar', $this->ids['c_deleted'])->payload['reason']);
        $this->assertSame('parent_order_archived', $this->legacy('kommentar', $this->ids['c_archived_parent'])->payload['reason']);

        $userId = DB::table('users')->where('email', 'user@alpha.example')->value('id');
        $this->assertTrue(DB::table('order_message_reads')->where(['order_id' => $orderId, 'user_id' => $userId])->exists());
        $this->assertTrue(DB::table('order_message_reads')->where(['order_id' => $orderId, 'user_id' => $adminId])->exists());
    }

    public function test_documents_are_copied_and_linked_to_their_comment_without_overwriting(): void
    {
        $this->buildScenario();
        Http::fake([
            'base44.app/*vertrag.pdf' => Http::response('PDF-BYTES-11', 200),
            'base44.app/*foto.jpg' => Http::response('JPG', 200),
        ]);

        $report = $this->runImport(['companies', 'users', 'vehicles', 'orders', 'messages', 'documents']);

        $number = $this->legacy('auftrag', $this->ids['o_return'])->payload['auftragsnummer'];
        $docs = DB::table('vehicle_report_documents')->where('auftragsnummer', $number)->orderBy('path')->get();

        $this->assertSame(3, $docs->count());
        $this->assertSame(['sonstiges'], $docs->pluck('document_type')->unique()->all());
        $this->assertSame([1], $docs->pluck('published')->map(fn ($p) => (int) $p)->unique()->all());
        $this->assertContains("vehicle-reports/{$number}/Vertrag.pdf", $docs->pluck('path')->all());
        $this->assertContains("vehicle-reports/{$number}/Vertrag-2.pdf", $docs->pluck('path')->all(), 'a repeated file name gets a suffix instead of replacing the first');
        Storage::disk('documents')->assertExists("vehicle-reports/{$number}/Foto.jpg");

        $fromComment = $this->legacy('dokument', $this->ids['c_files'].'#2');
        $this->assertSame($this->ids['c_files'], $fromComment->payload['comment_legacy_id']);
        $this->assertSame($this->legacy('kommentar', $this->ids['c_files'])->target_id, $fromComment->payload['message_id']);
        $this->assertSame('https://base44.app/api/apps/x/files/public/x/foto.jpg', $fromComment->payload['original_url']);
        $this->assertSame(hash('sha256', 'JPG'), $fromComment->payload['sha256']);

        $this->assertSame('archived', $this->legacy('dokument', $this->ids['d_pseudo'])->status);
        $this->assertSame(1, $report->has('dokument', 'warning', 'size_differs_from_source'), 'source says 11 bytes, 12 arrived');
    }

    public function test_a_failed_download_is_reported_and_retried_on_the_next_run(): void
    {
        $this->buildScenario();
        $available = false;
        Http::fake(function () use (&$available) {
            return $available ? Http::response('OK-BYTES', 200) : Http::response('nope', 500);
        });

        $first = $this->runImport(['companies', 'users', 'vehicles', 'orders', 'messages', 'documents']);

        $this->assertSame(0, DB::table('vehicle_report_documents')->count());
        $this->assertSame(3, $first->has('dokument', 'failed', 'download_failed'));
        $this->assertNull(LegacyImportMap::query()->where('entity', 'dokument')->where('legacy_id', $this->ids['d_file'])->first());

        $available = true;
        $second = $this->runImport(['documents']);

        $this->assertSame(3, DB::table('vehicle_report_documents')->count());
        $this->assertSame(3, $second->has('dokument', 'imported'));
    }

    public function test_attachments_from_other_hosts_are_never_fetched(): void
    {
        $this->buildScenario();
        $this->export->add('dateianhang', ['auftrag_id' => $this->ids['o_return'], 'speicherort' => 'https://evil.example/steal.pdf', 'dateiname' => 'x.pdf', 'dateigroesse' => '1']);
        Http::fake(['base44.app/*' => Http::response('OK', 200)]);

        $report = $this->runImport(['companies', 'users', 'vehicles', 'orders', 'messages', 'documents']);

        $this->assertSame(1, $report->has('dokument', 'skipped', 'host_not_allowed'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'evil.example'));
    }

    public function test_a_redirect_to_another_host_is_not_followed(): void
    {
        $this->buildScenario();
        Http::fake([
            'base44.app/*' => Http::response('', 302, ['Location' => 'https://evil.example/leak.pdf']),
            'evil.example/*' => Http::response('LEAK', 200),
        ]);

        $report = $this->runImport(['companies', 'users', 'vehicles', 'orders', 'messages', 'documents']);

        $this->assertSame(0, DB::table('vehicle_report_documents')->count());
        $this->assertSame(3, $report->has('dokument', 'failed', 'download_error'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'evil.example'));
    }

    public function test_leads_invitations_and_notifications_are_archived_or_only_reported(): void
    {
        $this->buildScenario();
        $report = $this->runImport(['companies', 'archive']);

        $this->assertSame('archived', $this->legacy('lead', $this->ids['lead'])->status);
        $this->assertSame('eingeladen@alpha.example', $this->legacy('einladung', $this->ids['einladung'])->payload['row']['email']);
        $this->assertSame(1, $report->has('benachrichtigung', 'skipped', 'not_migrated_by_design'));
        $this->assertNull(LegacyImportMap::query()->where('entity', 'benachrichtigung')->first());
    }

    public function test_a_second_identical_run_creates_nothing(): void
    {
        $this->buildScenario();
        Http::fake(['base44.app/*' => Http::response('BYTES', 200)]);

        $this->runImport();

        $snapshot = fn () => [
            'b2b' => DB::table('b2b')->count(), 'users' => DB::table('users')->count(), 'memberships' => DB::table('user_b2b')->count(),
            'vehicles' => DB::table('vehicles')->count(), 'orders' => DB::table('leasyback_orders')->count(),
            'logistics' => DB::table('leasyback_order_logistics')->count(), 'history' => DB::table('leasyback_order_status_updates')->count(),
            'notes' => DB::table('b2b_order_notes')->count(), 'messages' => DB::table('order_messages')->count(),
            'reads' => DB::table('order_message_reads')->count(), 'documents' => DB::table('vehicle_report_documents')->count(),
            'files' => count(Storage::disk('documents')->allFiles()), 'reservations' => DB::table('order_number_reservations')->count(),
            'map' => LegacyImportMap::query()->count(),
        ];

        $before = $snapshot();
        $second = $this->runImport();

        $this->assertSame($before, $snapshot());
        $this->assertSame(0, $second->has('kunde', 'imported') + $second->has('fahrzeug', 'imported') + $second->has('auftrag', 'imported') + $second->has('kommentar', 'imported') + $second->has('dokument', 'imported'));
        $this->assertGreaterThan(0, $second->has('fahrzeug', 'unchanged'));
    }

    public function test_a_dry_run_persists_nothing_and_fetches_nothing(): void
    {
        $this->buildScenario();
        Http::fake();

        $report = $this->runImport(dryRun: true);

        foreach (['b2b', 'users', 'user_b2b', 'vehicles', 'leasyback_orders', 'order_messages', 'vehicle_report_documents', 'order_number_reservations', 'legacy_import_map'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} must stay empty after a dry run");
        }

        $this->assertSame([], Storage::disk('documents')->allFiles());
        Http::assertNothingSent();
        $this->assertGreaterThan(0, $report->has('kunde', 'imported'), 'the dry run still reports what would happen');
        $this->assertSame(3, $report->has('dokument', 'planned'));
    }

    public function test_the_import_triggers_no_mail_notification_queue_event_or_stray_request(): void
    {
        $this->buildScenario();
        \Mail::fake();
        \Notification::fake();
        \Queue::fake();
        \Bus::fake();
        \Event::fake([Registered::class]);
        Http::fake(['base44.app/*' => Http::response('BYTES', 200)]);

        $this->runImport();

        \Mail::assertNothingSent();
        \Mail::assertNothingQueued();
        \Notification::assertNothingSent();
        \Queue::assertNothingPushed();
        \Bus::assertNothingDispatched();
        \Event::assertNotDispatched(Registered::class);
        Http::assertSentCount(3);
        $this->assertSame(0, DB::table('notifications')->count());
        $this->assertSame(0, DB::table('b2b_invitations')->count());
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
        $this->assertSame(0, DB::table('partner_webhook_events')->count());
    }

    public function test_only_the_requested_steps_run(): void
    {
        $this->buildScenario();

        $this->runImport(['companies']);

        $this->assertSame(4, DB::table('b2b')->count());
        $this->assertSame(0, DB::table('users')->count());
        $this->assertTrue(['companies', 'books', 'users', 'vehicles', 'orders', 'history', 'messages', 'documents', 'archive'] === ImportOptions::STEPS);
    }
}
