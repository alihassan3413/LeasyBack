<?php

namespace Tests\Feature\LegacyImport;

use App\Enums\B2bRolePreset;
use App\Models\B2B;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Feature\LegacyImport\Concerns\BuildsLegacyB2bImport;
use Tests\TestCase;

/**
 * legacy:export-b2b-users — the client's check of which Base44 user ended up
 * in which company, in which role. Read-only.
 */
class LegacyExportB2bUsersTest extends TestCase
{
    use BuildsLegacyB2bImport;
    use RefreshDatabase;

    /**
     * @return list<array<string, string>>
     */
    private function exportCsv(): array
    {
        $path = storage_path('framework/testing/b2b-report-'.uniqid().'.csv');

        $this->artisan('legacy:export-b2b-users', ['--format' => 'csv', '--output' => $path])->assertSuccessful();

        $content = (string) file_get_contents($path);
        unlink($path);

        $this->assertStringStartsWith("\xEF\xBB\xBF", $content, 'BOM so Excel reads the umlauts');

        $lines = array_map(fn (string $line) => str_getcsv($line, ';', '"', ''), array_values(array_filter(explode("\n", substr($content, 3)))));
        $headings = array_shift($lines);

        return array_map(fn (array $line) => array_combine($headings, $line), $lines);
    }

    /** @param list<array<string, string>> $rows */
    private function rowFor(array $rows, string $email): array
    {
        return collect($rows)->firstWhere('E-Mail', $email) ?? $this->fail("{$email} missing from the report");
    }

    public function test_users_are_grouped_by_company_with_the_administrator_first_and_german_labels(): void
    {
        $zeta = $this->importedCompany('Zeta GmbH', 'K-200');
        $alpha = $this->importedCompany('Alpha AG', 'K-100');

        $this->importedUser($zeta, 'z.member@zeta.test', 'Zora Zett', B2bRolePreset::StandardUser);
        $this->importedUser($zeta, 'z.admin@zeta.test', 'Zacharias Zett', B2bRolePreset::CompanyAdministrator);
        $this->importedUser($alpha, 'a.reader@alpha.test', 'Anna Alt', B2bRolePreset::ReadOnly);
        $this->importedUser($alpha, 'a.admin@alpha.test', 'Arne Alt', B2bRolePreset::CompanyAdministrator, 'linked');

        $rows = $this->exportCsv();

        $this->assertSame(['Unternehmen', 'B2B-ID', 'Vorname', 'Nachname', 'E-Mail', 'Rolle', 'Administrator', 'Benutzerstatus', 'Base44-Zuordnung/Quelle', 'Prüfung/Hinweis'], array_keys($rows[0]));
        $this->assertSame(['a.admin@alpha.test', 'a.reader@alpha.test', 'z.admin@zeta.test', 'z.member@zeta.test'], array_column($rows, 'E-Mail'));

        $this->assertSame([
            'Unternehmen' => 'Alpha AG',
            'B2B-ID' => $alpha->b2b_id,
            'Vorname' => 'Arne',
            'Nachname' => 'Alt',
            'E-Mail' => 'a.admin@alpha.test',
            'Rolle' => 'Unternehmensadministrator',
            'Administrator' => 'Ja',
            'Benutzerstatus' => 'Aktiv – bestehendes V2-Konto',
            'Base44-Zuordnung/Quelle' => 'Base44-Unternehmen (Kundennr. K-100), mit bestehendem V2-Konto verknüpft',
            'Prüfung/Hinweis' => 'OK',
        ], $rows[0]);

        $this->assertSame(['Nur Lesen', 'Nein'], [$rows[1]['Rolle'], $rows[1]['Administrator']]);
        $this->assertSame(['Standardbenutzer', 'Nein', 'Angelegt, noch nicht aktiviert'], [$rows[3]['Rolle'], $rows[3]['Administrator'], $rows[3]['Benutzerstatus']]);
    }

    public function test_suspicious_mappings_are_flagged(): void
    {
        // No administrator.
        $headless = $this->importedCompany('Ohne Admin GmbH');
        $this->importedUser($headless, 'solo@headless.test', 'Sven Solo', B2bRolePreset::StandardUser);

        // Two administrators.
        $crowded = $this->importedCompany('Zwei Admins GmbH');
        $this->importedUser($crowded, 'eins@crowded.test', 'Erika Eins', B2bRolePreset::CompanyAdministrator);
        $this->importedUser($crowded, 'zwei@crowded.test', 'Zoe Zwei', B2bRolePreset::CompanyAdministrator);

        // Imported, but the membership is gone: no company.
        $orphan = $this->importedUser($this->importedCompany('Weg GmbH'), 'weg@orphan.test', 'Wim Weg', B2bRolePreset::StandardUser);
        DB::table('user_b2b')->where('user_id', $orphan->id)->delete();

        // The cross-company case: bound to one company in Base44, yet created vehicles for others.
        $home = $this->importedCompany('Heimat GmbH');
        $this->importedUser($home, 'chef@home.test', 'Hanna Heim', B2bRolePreset::CompanyAdministrator);
        $helper = $this->importedUser($home, 'helfer@web.test', 'Viktor Helfer', B2bRolePreset::StandardUser);
        foreach (['Fremd 1 GmbH', 'Fremd 2 GmbH'] as $name) {
            $this->makeB2bVehicle($this->importedCompany($name), ['created_by_user_id' => $helper->id]);
        }
        $this->makeB2bVehicle($home, ['created_by_user_id' => $helper->id]);

        // Added to a second company in V2 after the import, with a changed role there.
        $extra = $this->importedCompany('Extra GmbH');
        $this->importedUser($extra, 'extra.chef@extra.test', 'Ella Extra', B2bRolePreset::CompanyAdministrator);
        $changed = $this->importedUser($this->importedCompany('Wechsel GmbH'), 'wechsel@changed.test', 'Wanda Wechsel', B2bRolePreset::CompanyAdministrator);
        DB::table('user_b2b')->where('user_id', $changed->id)->update(['role' => 'member', 'permissions' => json_encode(B2bRolePreset::StandardUser->permissions()->toArray())]);
        DB::table('user_b2b')->insert(['user_id' => $changed->id, 'b2b_id' => $extra->b2b_id, 'role' => 'member', 'permissions' => json_encode(B2bRolePreset::ReadOnly->permissions()->toArray()), 'vehicle_scope' => 'all', 'status' => 'active']);

        // Held back by the importer: an active Base44 user without a company.
        $this->map('user', 'ohne.firma@held.test', 'skipped', payload: ['reason' => 'active_user_without_company_manual_review']);

        $rows = $this->exportCsv();
        $flags = fn (string $email, ?string $company = null) => collect($rows)->where('E-Mail', $email)
            ->when($company, fn ($c) => $c->where('Unternehmen', $company))->first()['Prüfung/Hinweis'];

        $this->assertStringContainsString('Unternehmen hat keinen Administrator', $flags('solo@headless.test'));
        $this->assertStringContainsString('Unternehmen hat 2 Administratoren', $flags('eins@crowded.test'));
        $this->assertStringContainsString('Unternehmen hat 2 Administratoren', $flags('zwei@crowded.test'));
        $this->assertStringContainsString('Kein Unternehmen zugeordnet', $flags('weg@orphan.test'));
        $this->assertStringContainsString('Hat Fahrzeuge für 2 andere(s) Unternehmen angelegt', $flags('helfer@web.test'));
        $this->assertSame('OK', $flags('chef@home.test'));
        $this->assertStringContainsString('Rolle seit dem Import geändert', $flags('wechsel@changed.test', 'Wechsel GmbH'));
        $this->assertStringContainsString('Unternehmen stammt nicht aus der Base44-Zuordnung', $flags('wechsel@changed.test', 'Extra GmbH'));
        $this->assertStringContainsString('Mitglied in 2 Unternehmen', $flags('wechsel@changed.test', 'Extra GmbH'));

        $held = $this->rowFor($rows, 'ohne.firma@held.test');
        $this->assertSame(['', 'Nicht übernommen'], [$held['Unternehmen'], $held['Benutzerstatus']]);
        $this->assertStringContainsString('Manuelle Prüfung erforderlich', $held['Prüfung/Hinweis']);

        // Rows without a company come last.
        $this->assertSame('', end($rows)['Unternehmen']);
    }

    public function test_only_base44_company_users_are_listed_and_nothing_secret_is(): void
    {
        $company = $this->importedCompany('Alpha AG');
        $imported = $this->importedUser($company, 'imported@alpha.test', 'Ida Import', B2bRolePreset::CompanyAdministrator);
        $this->makeMember($company, B2bRolePreset::StandardUser->permissions()->toArray())->forceFill(['email' => 'v2.only@alpha.test'])->save();
        $this->map('user', 'staff@leasyback.com', 'skipped', payload: ['reason' => 'staff_admin_manual_review']);
        $this->map('user', 'invited@alpha.test', 'skipped', payload: ['reason' => 'invited_not_activated']);
        DB::table('legacy_activation_tokens')->insert(['email' => $imported->email, 'token' => 'hashed-token-value', 'created_at' => now()]);

        $rows = $this->exportCsv();

        $this->assertSame(['imported@alpha.test'], array_column($rows, 'E-Mail'));

        $text = json_encode($rows);
        $this->assertStringNotContainsString($imported->password, $text);
        $this->assertStringNotContainsString('hashed-token-value', $text);
        $this->assertStringNotContainsString((string) $imported->id, implode('|', array_column($rows, 'Base44-Zuordnung/Quelle')));
    }

    public function test_activation_progress_is_shown_as_the_user_status(): void
    {
        $company = $this->importedCompany('Alpha AG');
        $done = $this->importedUser($company, 'done@alpha.test', 'Dora Done', B2bRolePreset::CompanyAdministrator);
        $sent = $this->importedUser($company, 'sent@alpha.test', 'Sina Sent', B2bRolePreset::StandardUser);
        $off = $this->importedUser($company, 'off@alpha.test', 'Olaf Off', B2bRolePreset::StandardUser);
        $off->forceFill(['is_active' => false])->save();

        DB::table('legacy_activation_mails')->insert([
            ['user_id' => $done->id, 'email' => $done->email, 'status' => 'sent', 'activated_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $sent->id, 'email' => $sent->email, 'status' => 'sent', 'activated_at' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $rows = $this->exportCsv();

        $this->assertSame('Aktiv – Konto aktiviert', $this->rowFor($rows, 'done@alpha.test')['Benutzerstatus']);
        $this->assertSame('Aktivierungs-E-Mail versendet, noch nicht aktiviert', $this->rowFor($rows, 'sent@alpha.test')['Benutzerstatus']);
        $this->assertSame('Deaktiviert', $this->rowFor($rows, 'off@alpha.test')['Benutzerstatus']);
        $this->assertStringContainsString('Benutzerkonto deaktiviert', $this->rowFor($rows, 'off@alpha.test')['Prüfung/Hinweis']);
    }

    public function test_the_export_changes_no_user_company_or_role(): void
    {
        $company = $this->importedCompany('Alpha AG');
        $this->importedUser($company, 'admin@alpha.test', 'Arne Alt', B2bRolePreset::CompanyAdministrator);
        $this->importedUser($company, 'member@alpha.test', 'Mia Alt', B2bRolePreset::StandardUser);

        $snapshot = fn () => collect(['users', 'user_b2b', 'b2b', 'legacy_import_map', 'vehicles'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->map(fn ($row) => (array) $row)->all()])->all();
        $before = $snapshot();

        $this->exportCsv();
        $path = storage_path('framework/testing/b2b-report-'.uniqid().'.xlsx');
        $this->artisan('legacy:export-b2b-users', ['--output' => $path])->assertSuccessful();
        unlink($path);

        $this->assertSame($before, $snapshot());
    }

    public function test_the_default_format_is_a_readable_xlsx(): void
    {
        $company = $this->importedCompany('Altfirma Süd GmbH');
        $this->importedUser($company, 'admin@sued.test', 'Jörg Müller', B2bRolePreset::CompanyAdministrator);
        $path = storage_path('framework/testing/b2b-report-'.uniqid().'.xlsx');

        $this->artisan('legacy:export-b2b-users', ['--output' => $path])
            ->expectsOutputToContain('Report: '.$path)
            ->doesntExpectOutputToContain('admin@sued.test')
            ->assertSuccessful();

        $reader = new Reader;
        $reader->open($path);
        $values = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $values[] = $row->toArray();
            }
        }
        $reader->close();
        unlink($path);

        $this->assertSame('Unternehmen', $values[0][0]);
        $this->assertSame(['Altfirma Süd GmbH', 'Jörg', 'Müller', 'Unternehmensadministrator', 'Ja'], [$values[1][0], $values[1][2], $values[1][3], $values[1][5], $values[1][6]]);
    }

    public function test_an_unknown_format_is_refused(): void
    {
        $this->artisan('legacy:export-b2b-users', ['--format' => 'pdf'])->assertFailed();
    }
}
