<?php

namespace Tests\Feature\LegacyImport;

use App\Enums\B2bRole;
use App\Enums\B2bRolePreset;
use App\Models\LegacyImportMap;
use App\Models\User;
use App\Modules\UserProfile\B2B\Data\B2bPermissionSet;
use App\Services\Mfa\MfaRecoveryCodeService;
use App\Services\Mfa\MfaTotpService;
use App\Support\LegacyImport\LegacyB2bUserReport;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Tests\Support\LegacyExportFixture;

/**
 * The client's final corrections, end to end against the real importer: a
 * Base44 export is imported, the demo user activates the account (password +
 * MFA) as in production, the correction runs, and a later import runs again.
 *
 * The demo user moves from Altfirma to Testfirma as its only administrator
 * without anything on the account changing; the old Testfirma administrator
 * and a staff account are removed but stay the creators of their records; a
 * held-back user joins Kundenfirma as a Standardnutzer while its
 * administrator stays; another held-back user stays out.
 */
class LegacyCorrectB2bUsersImportTest extends LegacyImportTestCase
{
    private const DEMO_PASSWORD = 'Vorfuehrer-eigenes-Passwort-1!';

    private string $reports;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reports = storage_path('framework/testing/corrections-'.uniqid());
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->reports.'/*') ?: []);
        is_dir($this->reports) && rmdir($this->reports);

        parent::tearDown();
    }

    private function buildExport(): void
    {
        $e = $this->export;
        $u = fn (array $v) => $e->add('users', $v + ['status' => 'active', 'role' => 'user']);

        $this->ids['altfirma'] = $e->add('kunde', ['firmenname' => 'Altfirma GmbH', 'aktiv' => true, 'kontaktperson_email' => 'dora@altfirma.example']);
        $this->ids['testfirma'] = $e->add('kunde', ['firmenname' => 'Testfirma GmbH', 'aktiv' => false, 'kontaktperson_email' => 'alt.admin@leasyback.com']);
        $this->ids['kundenfirma'] = $e->add('kunde', ['firmenname' => 'Kundenfirma GmbH', 'aktiv' => true, 'kontaktperson_email' => 'service@kundenfirma.example']);

        $u(['email' => 'vorfuehrer@web.example', 'anzeigename' => 'Viktor Vorstell', 'kunde_id' => $this->ids['altfirma'], 'created_date' => '2026-01-28T10:00:00.000000']);
        $u(['email' => 'dora@altfirma.example', 'kunde_id' => $this->ids['altfirma'], 'created_date' => '2026-09-28T10:00:00.000000']);
        $u(['email' => 'alt.admin@leasyback.com', 'kunde_id' => $this->ids['testfirma']]);
        $u(['email' => 'service@kundenfirma.example', 'anzeigename' => 'Kundenfirma', 'kunde_id' => $this->ids['kundenfirma'], 'created_date' => '2026-02-11T10:00:00.000000']);
        $u(['email' => 'werbung@leasyback.com', 'kunde_id' => $this->ids['kundenfirma'], 'created_date' => '2026-03-01T10:00:00.000000']);
        $u(['email' => 'neu@kundenfirma.example', 'kunde_id' => '']);
        $u(['email' => 'ohne.firma@mail.example', 'kunde_id' => '']);

        $car = fn (string $kunde, string $plate, string $by) => $e->add('fahrzeug', ['kunde_id' => $kunde, 'kennzeichen' => $plate, 'hersteller' => 'Renault', 'modell' => 'Clio', 'created_date' => '2026-02-01T10:00:00.000000', 'created_by' => $by]);
        $this->ids['demo_car'] = $car($this->ids['testfirma'], 'B-TF 1', 'alt.admin@leasyback.com');
        $this->ids['kunden_car'] = $car($this->ids['kundenfirma'], 'B-CV 1', 'werbung@leasyback.com');
        $this->ids['demo_car2'] = $car($this->ids['kundenfirma'], 'B-CV 2', 'vorfuehrer@web.example');

        $this->ids['order'] = $e->add('auftrag', ['kunde_id' => $this->ids['kundenfirma'], 'typ' => 'GUTACHTEN', 'status' => 'Abgeschlossen', 'tracking_status' => 'Auftrag abgeschlossen', 'fahrzeug_ids' => [$this->ids['kunden_car']], 'gutachten_standort_ort' => 'Köln', 'created_date' => '2026-04-10T08:30:00.000000', 'angelegt_von_nutzer_id' => '', 'created_by' => 'werbung@leasyback.com']);
        $e->add('historie', ['auftrag_id' => $this->ids['order'], 'neuer_status' => 'Gutachten terminiert', 'alter_status' => 'Neu eingegangen', 'created_date' => '2026-04-12T09:00:00.000000', 'geaendert_von_email' => 'werbung@leasyback.com']);
    }

    /** What the demo user did in production: set a password through the activation link and enrolled TOTP. */
    private function demoUserActivates(): string
    {
        $demo = User::where('email', 'vorfuehrer@web.example')->firstOrFail();
        $secret = app(MfaTotpService::class)->generateSecret();
        $demo->forceFill([
            'password' => self::DEMO_PASSWORD,
            'mfa_secret' => $secret,
            'mfa_method' => 'totp',
            'mfa_confirmed_at' => now()->subDay(),
            'mfa_recovery_codes' => app(MfaRecoveryCodeService::class)->generate()['hashed'],
        ])->save();
        DB::table('legacy_activation_mails')->insert(['user_id' => $demo->id, 'email' => $demo->email, 'status' => 'sent', 'attempts' => 1, 'sent_at' => now()->subDays(2), 'activated_at' => now()->subDay(), 'created_at' => now()->subDays(2), 'updated_at' => now()->subDay()]);

        return $secret;
    }

    private function b2bId(string $kunde): string
    {
        return (string) LegacyImportMap::where('entity', 'kunde')->where('legacy_id', $this->ids[$kunde])->value('target_id');
    }

    /** @return array<string, mixed> */
    private function correctionOptions(): array
    {
        return [
            '--remove' => ['alt.admin@leasyback.com', 'werbung@leasyback.com', 'ohne.firma@mail.example'],
            '--assign' => ["vorfuehrer@web.example:{$this->ids['testfirma']}:company_administrator", "neu@kundenfirma.example:{$this->ids['kundenfirma']}:standard_user"],
            '--expect-admin' => ["{$this->ids['testfirma']}:vorfuehrer@web.example", "{$this->ids['kundenfirma']}:service@kundenfirma.example"],
            '--activate-company' => [$this->ids['testfirma']],
            '--report-path' => $this->reports,
        ];
    }

    /** @return array<string, mixed> */
    private function state(): array
    {
        return collect(['users', 'user_b2b', 'b2b', 'legacy_import_map', 'legacy_activation_mails', 'legacy_activation_tokens', 'vehicles', 'leasyback_orders', 'leasyback_order_status_updates'])
            ->mapWithKeys(fn ($t) => [$t => DB::table($t)->get()->map(fn ($r) => (array) $r)->all()])->all();
    }

    /** @return list<array{b2b: string, email: string, role: string}> */
    private function members(string $kunde): array
    {
        return DB::table('user_b2b as ub')->join('users as u', 'u.id', '=', 'ub.user_id')->where('ub.b2b_id', $this->b2bId($kunde))
            ->orderBy('u.email')->get(['u.email', 'ub.role', 'ub.permissions'])
            ->map(fn ($m) => ['email' => $m->email, 'preset' => B2bRolePreset::match(B2bRole::from($m->role), B2bPermissionSet::fromRaw(json_decode((string) $m->permissions, true) ?? []))?->value])
            ->all();
    }

    public function test_the_final_client_corrections(): void
    {
        $this->buildExport();
        $this->runImport();
        $totpSecret = $this->demoUserActivates();

        $demo = DB::table('users')->where('email', 'vorfuehrer@web.example')->first();
        $credentialColumns = ['id', 'name', 'email', 'email_verified_at', 'password', 'remember_token', 'is_active', 'user_type', 'mfa_secret', 'mfa_recovery_codes', 'mfa_confirmed_at', 'mfa_method', 'mfa_last_used_step', 'mfa_email_confirmed_at', 'created_at'];
        $demoBefore = array_intersect_key((array) $demo, array_flip($credentialColumns));
        $activationBefore = (array) DB::table('legacy_activation_mails')->where('user_id', $demo->id)->first();
        $service = DB::table('user_b2b as ub')->join('users as u', 'u.id', '=', 'ub.user_id')->where('u.email', 'service@kundenfirma.example')->first(['ub.*']);
        $werbung = User::where('email', 'werbung@leasyback.com')->firstOrFail();
        $werbung->forceFill(['password' => 'werbung-pass-1!'])->save();
        $creators = fn () => [
            DB::table('vehicles')->orderBy('vehicle_id')->pluck('created_by_user_id', 'vehicle_id')->all(),
            DB::table('leasyback_orders')->pluck('created_by_user_id', 'id')->all(),
            DB::table('leasyback_order_status_updates')->pluck('updated_by_user_id', 'id')->all(),
        ];
        $creatorsBefore = $creators();

        $this->assertSame([['email' => 'dora@altfirma.example', 'preset' => 'company_administrator'], ['email' => 'vorfuehrer@web.example', 'preset' => 'standard_user']], $this->members('altfirma'));

        $this->artisan('legacy:correct-b2b-users', [...$this->correctionOptions(), '--apply' => true])->assertSuccessful();

        // The demo user: moved to Testfirma as its only administrator; nothing left at Altfirma.
        $this->assertSame([['email' => 'dora@altfirma.example', 'preset' => 'company_administrator']], $this->members('altfirma'));
        $this->assertSame([['email' => 'vorfuehrer@web.example', 'preset' => 'company_administrator']], $this->members('testfirma'));
        $this->assertSame($this->b2bId('testfirma'), DB::table('users')->where('id', $demo->id)->value('active_b2b_id'));

        // ...and his account is exactly as he left it: password, MFA, recovery codes, e-mail, activation.
        $demoAfter = array_intersect_key((array) DB::table('users')->where('id', $demo->id)->first(), array_flip($credentialColumns));
        $this->assertSame($demoBefore, $demoAfter);
        $this->assertSame($activationBefore, (array) DB::table('legacy_activation_mails')->where('user_id', $demo->id)->first());
        $this->assertFalse(DB::table('legacy_activation_tokens')->where('email', 'vorfuehrer@web.example')->exists());

        // Testfirma stays, switched on, with its demo vehicle.
        $this->assertTrue((bool) DB::table('b2b')->where('b2b_id', $this->b2bId('testfirma'))->value('is_active'));
        $this->assertTrue(DB::table('vehicles')->where('b2b_id', $this->b2bId('testfirma'))->exists());

        // Kundenfirma: service@kundenfirma untouched and still its only administrator; the new user is a Standardnutzer.
        $this->assertSame([
            ['email' => 'neu@kundenfirma.example', 'preset' => 'standard_user'],
            ['email' => 'service@kundenfirma.example', 'preset' => 'company_administrator'],
        ], $this->members('kundenfirma'));
        $this->assertEquals($service, DB::table('user_b2b')->where('user_id', $service->user_id)->where('b2b_id', $service->b2b_id)->first());

        // Removed: no membership, cannot sign in, still the creators of their records.
        foreach (['alt.admin@leasyback.com', 'werbung@leasyback.com'] as $email) {
            $user = User::where('email', $email)->firstOrFail();
            $this->assertFalse((bool) $user->is_active);
            $this->assertFalse(DB::table('user_b2b')->where('user_id', $user->id)->exists());
        }
        $this->assertSame($creatorsBefore, $creators());
        $this->assertFalse(User::where('email', 'ohne.firma@mail.example')->exists());

        $this->post('/login', ['email' => 'werbung@leasyback.com', 'password' => 'werbung-pass-1!'])->assertSessionHasErrors();
        $this->assertGuest();

        // The demo user signs in with the current password and the current authenticator.
        $totp = app(MfaTotpService::class);
        $this->post('/login', ['email' => 'vorfuehrer@web.example', 'password' => self::DEMO_PASSWORD])->assertRedirect(route('mfa.verify'));
        $this->post(route('mfa.verify.store'), ['code' => $totp->codeAt($totpSecret, $totp->stepAt())])->assertRedirect();
        $this->assertAuthenticatedAs(User::find($demo->id));
        auth()->logout();

        // Activation: the new user yes, the demo user is already activated, the removed ones never.
        $this->artisan('legacy:send-activation-emails', ['--dry-run' => true, '--email' => 'neu@kundenfirma.example'])->expectsOutputToContain('Would be mailed now')->doesntExpectOutputToContain('not mailed');
        $this->artisan('legacy:send-activation-emails', ['--dry-run' => true, '--email' => 'vorfuehrer@web.example'])->expectsOutputToContain('not mailed — already_activated');
        foreach (['alt.admin@leasyback.com', 'werbung@leasyback.com', 'ohne.firma@mail.example'] as $email) {
            $this->artisan('legacy:send-activation-emails', ['--dry-run' => true, '--email' => $email])->expectsOutputToContain('not mailed — import_status_removed');
        }

        // Running it again changes nothing; neither does a later import, even from a re-exported source.
        $state = $this->state();
        $this->artisan('legacy:correct-b2b-users', [...$this->correctionOptions(), '--apply' => true])->expectsOutputToContain('Everything is already in place')->assertSuccessful();
        $this->runImport();
        $this->export->destroy();
        $this->export = new LegacyExportFixture;
        $this->buildExport();
        $this->runImport();
        $this->assertSame($state, $this->state());

        // The report shows the approved state without raising it as a problem.
        $path = $this->reports.'/report.csv';
        $this->artisan('legacy:export-b2b-users', ['--format' => 'csv', '--output' => $path])->expectsOutputToContain('Zeilen mit Prüfhinweis')->assertSuccessful();
        $lines = array_map(fn ($line) => str_getcsv($line, ';', '"', ''), array_values(array_filter(explode("\n", substr((string) file_get_contents($path), 3)))));
        $rows = collect(array_slice($lines, 1))->map(fn ($line) => array_combine($lines[0], $line));
        $row = fn (string $email) => $rows->firstWhere('E-Mail', $email);

        $this->assertSame(['Testfirma GmbH', 'Unternehmensadministrator', 'Ja', 'Aktiv – Konto aktiviert'], [$row('vorfuehrer@web.example')['Unternehmen'], $row('vorfuehrer@web.example')['Rolle'], $row('vorfuehrer@web.example')['Administrator'], $row('vorfuehrer@web.example')['Benutzerstatus']]);
        $this->assertSame(1, $rows->where('E-Mail', 'vorfuehrer@web.example')->count());
        $this->assertStringStartsWith('Freigegebene Korrektur: von Altfirma GmbH zu Testfirma GmbH verschoben', $row('vorfuehrer@web.example')['Prüfung/Hinweis']);
        $this->assertSame(['Kundenfirma GmbH', 'Standardbenutzer', 'Nein'], [$row('neu@kundenfirma.example')['Unternehmen'], $row('neu@kundenfirma.example')['Rolle'], $row('neu@kundenfirma.example')['Administrator']]);
        $this->assertSame(['Unternehmensadministrator', 'Ja', 'OK'], [$row('service@kundenfirma.example')['Rolle'], $row('service@kundenfirma.example')['Administrator'], $row('service@kundenfirma.example')['Prüfung/Hinweis']]);

        foreach (['alt.admin@leasyback.com', 'werbung@leasyback.com', 'ohne.firma@mail.example'] as $email) {
            $this->assertSame(['', 'Entfernt'], [$row($email)['Unternehmen'], $row($email)['Benutzerstatus']]);
        }

        $this->assertSame([], $rows->filter(fn ($r) => LegacyB2bUserReport::needsReview($r))->pluck('E-Mail')->values()->all());
    }
}
