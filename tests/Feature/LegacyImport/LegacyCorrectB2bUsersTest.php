<?php

namespace Tests\Feature\LegacyImport;

use App\Enums\B2bRolePreset;
use App\Enums\UserType;
use App\Models\B2B;
use App\Models\LegacyImportMap;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Tests\Feature\LegacyImport\Concerns\BuildsLegacyB2bImport;
use Tests\TestCase;

/**
 * legacy:correct-b2b-users — the safeguards: preview by default, all or
 * nothing, idempotent, audited, and blocking on any state the client's
 * decision did not account for. The full client scenario against the real
 * importer is LegacyCorrectB2bUsersImportTest.
 */
class LegacyCorrectB2bUsersTest extends TestCase
{
    use BuildsLegacyB2bImport;
    use RefreshDatabase;

    private string $reports;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reports = storage_path('framework/testing/corrections-'.uniqid());
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->reports.'/*') ?: []);
        is_dir($this->reports) && rmdir($this->reports);

        parent::tearDown();
    }

    /**
     * Testfirma run by an account to be removed, Kundenfirma with its
     * administrator, the demo user at Altfirma, the new user held back without a company.
     *
     * @return array<string, mixed>
     */
    private function scenario(): array
    {
        $altfirma = $this->importedCompany('Altfirma GmbH');
        $testfirma = $this->importedCompany('Testfirma GmbH');
        $kundenfirma = $this->importedCompany('Kundenfirma GmbH');

        $this->importedUser($altfirma, 'dora@altfirma.test', 'Dora Bestand', B2bRolePreset::CompanyAdministrator);
        $demo = $this->importedUser($altfirma, 'vorfuehrer@web.test', 'Viktor Vorstell', B2bRolePreset::StandardUser);
        $oldAdmin = $this->importedUser($testfirma, 'alt.admin@leasyback.com', 'Alt Admin', B2bRolePreset::CompanyAdministrator);
        $service = $this->importedUser($kundenfirma, 'service@kundenfirma.test', 'Kundenfirma', B2bRolePreset::CompanyAdministrator);
        $this->map('user', 'neu@kundenfirma.test', 'skipped', payload: ['reason' => 'active_user_without_company_manual_review']);

        $kunde = fn (B2B $company) => (string) LegacyImportMap::where('entity', 'kunde')->where('target_id', $company->b2b_id)->value('legacy_id');

        return [
            'altfirma' => $altfirma, 'testfirma' => $testfirma, 'kundenfirma' => $kundenfirma,
            'demo' => $demo, 'oldAdmin' => $oldAdmin, 'service' => $service,
            'kAltfirma' => $kunde($altfirma), 'kTestfirma' => $kunde($testfirma), 'kKundenfirma' => $kunde($kundenfirma),
        ];
    }

    /** @return array<string, mixed> */
    private function correctionOptions(array $s): array
    {
        return [
            '--remove' => ['alt.admin@leasyback.com'],
            '--assign' => ["vorfuehrer@web.test:{$s['kTestfirma']}:company_administrator", "neu@kundenfirma.test:{$s['kKundenfirma']}:standard_user"],
            '--expect-admin' => ["{$s['kTestfirma']}:vorfuehrer@web.test", "{$s['kKundenfirma']}:service@kundenfirma.test"],
        ];
    }

    private function correct(array $options): PendingCommand
    {
        return $this->artisan('legacy:correct-b2b-users', [...$options, '--report-path' => $this->reports]);
    }

    /** @return array<string, mixed> */
    private function state(): array
    {
        return collect(['users', 'user_b2b', 'b2b', 'legacy_import_map', 'legacy_activation_tokens'])
            ->mapWithKeys(fn ($t) => [$t => DB::table($t)->get()->map(fn ($r) => (array) $r)->all()])->all();
    }

    public function test_the_preview_changes_nothing(): void
    {
        $s = $this->scenario();
        $before = $this->state();

        $this->correct($this->correctionOptions($s))
            ->expectsOutputToContain('Preview only')
            ->expectsOutputToContain('Danach genau ein Unternehmensadministrator: vorfuehrer@web.test')
            ->assertSuccessful();

        $this->assertSame($before, $this->state());
        $this->assertDirectoryDoesNotExist($this->reports);
    }

    public function test_it_applies_once_audits_and_then_changes_nothing(): void
    {
        $s = $this->scenario();

        $this->correct([...$this->correctionOptions($s), '--apply' => true])->assertSuccessful();

        $audit = json_decode(file_get_contents(glob($this->reports.'/correction-*.json')[0]), true);
        $this->assertEqualsCanonicalizing(['removed', 'assigned', 'account_created', 'assigned'], array_column($audit['changes'], 'action'));
        $move = collect($audit['changes'])->firstWhere('email', 'vorfuehrer@web.test');
        $this->assertSame('Altfirma GmbH', $move['removed_memberships'][0]['company_name']);
        $this->assertSame('Testfirma GmbH', collect($audit['changes'])->firstWhere('email', 'alt.admin@leasyback.com')['memberships'][0]['company_name']);

        $after = $this->state();
        $this->correct([...$this->correctionOptions($s), '--apply' => true])->expectsOutputToContain('Everything is already in place')->assertSuccessful();

        $this->assertSame($after, $this->state());
        $this->assertCount(1, glob($this->reports.'/correction-*.json'));
    }

    public function test_an_existing_administrator_is_never_demoted_it_blocks(): void
    {
        $s = $this->scenario();

        // The new user as administrator of Kundenfirma would make two: the run refuses rather than demote service@kundenfirma.
        $this->correct(['--assign' => ["neu@kundenfirma.test:{$s['kKundenfirma']}:company_administrator"], '--apply' => true])
            ->expectsOutputToContain('Hätte danach 2 Unternehmensadministratoren')
            ->assertFailed();

        $this->assertSame('owner', DB::table('user_b2b')->where('user_id', $s['service']->id)->value('role'));
        $this->assertFalse(User::where('email', 'neu@kundenfirma.test')->exists());
    }

    public function test_a_company_left_without_its_administrator_blocks(): void
    {
        $s = $this->scenario();

        $this->correct(['--remove' => ['alt.admin@leasyback.com'], '--apply' => true])
            ->expectsOutputToContain('Hätte danach keinen Unternehmensadministrator')
            ->assertFailed();

        $this->correct(['--assign' => ["dora@altfirma.test:{$s['kKundenfirma']}:standard_user"], '--apply' => true])
            ->expectsOutputToContain('Hätte danach keinen Unternehmensadministrator')
            ->assertFailed();

        $this->assertTrue((bool) $s['oldAdmin']->fresh()->is_active);
    }

    public function test_an_unexpected_administrator_blocks(): void
    {
        $s = $this->scenario();

        $this->correct([...$this->correctionOptions($s), '--expect-admin' => ["{$s['kKundenfirma']}:jemand@anders.test"], '--apply' => true])
            ->expectsOutputToContain('erwartet jemand@anders.test')
            ->assertFailed();

        $this->assertSame('member', DB::table('user_b2b')->where('user_id', $s['demo']->id)->value('role'));
    }

    public function test_unexpected_accounts_block_the_whole_run(): void
    {
        $s = $this->scenario();
        $before = $this->state();

        // The demo user deactivated: the account must stay active, the correction never reactivates or guesses.
        $s['demo']->forceFill(['is_active' => false])->save();
        $this->correct([...$this->correctionOptions($s), '--apply' => true])->expectsOutputToContain('V2-Konto ist deaktiviert')->assertFailed();
        $s['demo']->forceFill(['is_active' => true])->save();

        // An account for the new user appeared that the import map does not know.
        $newUser = User::factory()->create(['email' => 'neu@kundenfirma.test', 'user_type' => UserType::Firmenkunde]);
        $this->correct([...$this->correctionOptions($s), '--apply' => true])->expectsOutputToContain('passt nicht zur Import-Zuordnung')->assertFailed();
        $newUser->delete();

        // A user the importer held back for another reason.
        LegacyImportMap::where('legacy_id', 'neu@kundenfirma.test')->update(['payload' => json_encode(['reason' => 'invited_not_activated'])]);
        $this->correct([...$this->correctionOptions($s), '--apply' => true])->expectsOutputToContain('aus anderem Grund zurückgehalten')->assertFailed();
        LegacyImportMap::where('legacy_id', 'neu@kundenfirma.test')->update(['payload' => json_encode(['reason' => 'active_user_without_company_manual_review'])]);

        foreach ([
            ['--remove' => ['unbekannt@nirgends.test']],
            ['--remove' => ['vorfuehrer@web.test'], '--assign' => ["vorfuehrer@web.test:{$s['kTestfirma']}:company_administrator"]],
            ['--assign' => ['vorfuehrer@web.test:kein-solcher-kunde:company_administrator']],
        ] as $options) {
            $this->correct([...$options, '--apply' => true])->expectsOutputToContain('Blocked')->assertFailed();
        }

        $this->assertEquals($before, $this->state());
    }

    public function test_accounts_the_import_did_not_create_are_never_removed(): void
    {
        $company = $this->importedCompany('Alpha AG');
        $this->importedUser($company, 'chef@alpha.test', 'Chef Alpha', B2bRolePreset::CompanyAdministrator);
        $this->importedUser($company, 'vorher@alpha.test', 'Vera Vorher', B2bRolePreset::StandardUser, 'linked');
        $staff = $this->importedUser($company, 'staff@alpha.test', 'Stefan Staff', B2bRolePreset::StandardUser);
        $staff->forceFill(['user_type' => UserType::Admin])->save();

        $this->correct(['--remove' => ['vorher@alpha.test'], '--apply' => true])->expectsOutputToContain('Bestehendes V2-Konto')->assertFailed();
        $this->correct(['--remove' => ['staff@alpha.test'], '--apply' => true])->expectsOutputToContain('kein Firmenkunde')->assertFailed();
    }

    public function test_a_company_is_switched_on_only_when_asked(): void
    {
        $s = $this->scenario();
        $s['testfirma']->forceFill(['is_active' => false])->save();

        $this->correct([...$this->correctionOptions($s), '--apply' => true])->assertSuccessful();
        $this->assertFalse((bool) $s['testfirma']->fresh()->is_active);

        $this->correct([...$this->correctionOptions($s), '--activate-company' => [$s['kTestfirma']], '--apply' => true])->assertSuccessful();
        $this->assertTrue((bool) $s['testfirma']->fresh()->is_active);
    }

    public function test_production_requires_confirmation_to_apply_but_not_to_preview(): void
    {
        $s = $this->scenario();
        $this->app->detectEnvironment(fn () => 'production');

        $this->correct($this->correctionOptions($s))->assertSuccessful();
        $this->correct([...$this->correctionOptions($s), '--apply' => true])->assertFailed();
        $this->assertSame('member', DB::table('user_b2b')->where('user_id', $s['demo']->id)->value('role'));

        $this->correct([...$this->correctionOptions($s), '--apply' => true, '--confirm-production' => true])->assertSuccessful();
        $this->assertSame('owner', DB::table('user_b2b')->where('user_id', $s['demo']->id)->value('role'));
    }

    public function test_options_are_validated(): void
    {
        $this->correct([])->assertFailed();
        $this->correct(['--assign' => ['vorfuehrer@web.test:k1']])->assertFailed();
        $this->correct(['--assign' => ['vorfuehrer@web.test:k1:owner']])->assertFailed();
        $this->correct(['--assign' => ['vorfuehrer@web.test:k1:standard_user', 'vorfuehrer@web.test:k2:standard_user']])->assertFailed();
        $this->correct(['--remove' => ['a@b.test'], '--expect-admin' => ['nur-kunde']])->assertFailed();
    }
}
