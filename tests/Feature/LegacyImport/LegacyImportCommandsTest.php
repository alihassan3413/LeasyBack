<?php

namespace Tests\Feature\LegacyImport;

use App\Models\LegacyImportMap;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;

class LegacyImportCommandsTest extends LegacyImportTestCase
{
    private string $reports;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reports = sys_get_temp_dir().DIRECTORY_SEPARATOR.'legacy-import-reports-'.Str::random(8);
        Http::fake(['base44.app/*' => Http::response('BYTES', 200)]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->reports.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }

        foreach (glob($this->reports.DIRECTORY_SEPARATOR.'*') ?: [] as $dir) {
            @rmdir($dir);
        }

        @rmdir($this->reports);

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function importCommand(array $options = []): PendingCommand
    {
        $this->export->write();

        return $this->artisan('legacy:import', ['--source' => $this->export->directory, '--report-path' => $this->reports] + $options);
    }

    private function batchOfLastRun(): string
    {
        return (string) LegacyImportMap::query()->orderByDesc('id')->value('batch_id');
    }

    public function test_production_requires_an_explicit_confirmation_flag(): void
    {
        $this->buildScenario();
        $this->app->detectEnvironment(fn () => 'production');

        $this->importCommand()->expectsOutputToContain('--confirm-production')->assertFailed();
        $this->assertSame(0, DB::table('b2b')->count());
        $this->assertSame(0, LegacyImportMap::query()->count());

        $this->importCommand(['--confirm-production' => true, '--step' => ['companies']])->assertSuccessful();
        $this->assertSame(4, DB::table('b2b')->count());
    }

    public function test_a_dry_run_is_allowed_on_production_without_the_flag_and_writes_a_report_only(): void
    {
        $this->buildScenario();
        $this->app->detectEnvironment(fn () => 'production');

        $this->importCommand(['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, DB::table('b2b')->count());

        $csv = glob($this->reports.DIRECTORY_SEPARATOR.'*-dry-run'.DIRECTORY_SEPARATOR.'reconciliation.csv');
        $this->assertCount(1, $csv);
        $this->assertStringContainsString('entity,legacy_id,action,reason,detail', file_get_contents($csv[0]));
        $this->assertFileExists(dirname($csv[0]).DIRECTORY_SEPARATOR.'summary.json');
    }

    public function test_unknown_steps_and_a_missing_export_are_refused(): void
    {
        $this->importCommand(['--step' => ['nonsense']])->expectsOutputToContain('Unknown step')->assertFailed();

        $this->artisan('legacy:import', ['--source' => $this->export->directory.'-missing'])->assertFailed();
    }

    public function test_the_report_lists_every_skipped_changed_and_deduplicated_row(): void
    {
        $this->buildScenario();
        $this->importCommand(['--step' => ['companies', 'users', 'vehicles', 'orders']])->assertSuccessful();

        $csv = file_get_contents(glob($this->reports.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'reconciliation.csv')[0]);

        foreach (['staff_admin_manual_review', 'invited_not_activated', 'dummy_vehicle_unreferenced', 'orphan_kunde', 'no_users_vehicles_or_orders', 'no_resolvable_vehicle', 'vehicle_has_open_order', 'service_type_not_supported_in_v2:SONSTIGES'] as $reason) {
            $this->assertStringContainsString($reason, $csv);
        }
    }

    public function test_a_row_changed_in_the_source_is_reported_not_applied(): void
    {
        $this->buildScenario();
        $this->importCommand(['--step' => ['companies']])->assertSuccessful();
        $b2bId = $this->legacyTarget('kunde', $this->ids['alpha']);

        $path = $this->export->directory.DIRECTORY_SEPARATOR.'Kunde_export.csv';
        file_put_contents($path, str_replace('Testfirma Alpha GmbH', 'Alpha Umbenannt GmbH', file_get_contents($path)));

        $this->artisan('legacy:import', ['--source' => $this->export->directory, '--report-path' => $this->reports, '--step' => ['companies']])->assertSuccessful();

        $this->assertSame('Testfirma Alpha GmbH', DB::table('b2b')->where('b2b_id', $b2bId)->value('company_name'));
        $csv = implode("\n", array_map('file_get_contents', glob($this->reports.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'reconciliation.csv')));
        $this->assertStringContainsString('changed_at_source', $csv);
    }

    private function legacyTarget(string $entity, string $id): string
    {
        return (string) LegacyImportMap::query()->where('entity', $entity)->where('legacy_id', $id)->value('target_id');
    }

    public function test_rollback_removes_what_the_batch_created_and_keeps_pre_existing_accounts(): void
    {
        $this->buildScenario();
        $existingId = DB::table('users')->insertGetId(['name' => 'Bestand', 'email' => 'user@alpha.example', 'password' => 'keep', 'user_type' => 'Firmenkunde', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $adminId = DB::table('users')->insertGetId(['name' => 'Staff', 'email' => 'staff@leasyback.com', 'password' => 'keep', 'user_type' => 'Admin', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        $this->importCommand()->assertSuccessful();
        $this->assertGreaterThan(0, DB::table('vehicle_report_documents')->count());
        $batch = $this->batchOfLastRun();

        $this->artisan('legacy:rollback', ['batch' => $batch, '--report-path' => $this->reports, '--dry-run' => true])->assertSuccessful();
        $this->assertGreaterThan(0, DB::table('vehicles')->count(), 'a dry-run rollback changes nothing');
        $this->assertNotSame([], Storage::disk('documents')->allFiles(), 'a dry-run rollback never deletes stored files');
        $this->assertSame(DB::table('vehicle_report_documents')->count() + DB::table('leasyback_order_attachments')->count(), count(Storage::disk('documents')->allFiles()));

        $this->artisan('legacy:rollback', ['batch' => $batch, '--report-path' => $this->reports])->assertSuccessful();

        foreach (['b2b', 'addresses', 'contacts', 'phone_numbers', 'user_b2b', 'vehicles', 'leasyback_orders', 'leasyback_order_logistics', 'leasyback_order_status_updates', 'b2b_order_notes', 'order_messages', 'order_message_reads', 'vehicle_report_documents', 'leasyback_order_vehicles', 'leasyback_order_attachments', 'company_billing_addresses', 'company_cost_centres', 'order_number_reservations', 'legacy_import_map'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} must be empty after rollback");
        }

        $this->assertSame(2, DB::table('users')->count(), 'imported users are removed, the two that pre-dated the import stay');
        $this->assertSame('keep', DB::table('users')->where('id', $existingId)->value('password'));
        $this->assertNotNull(DB::table('users')->where('id', $adminId)->first());
        $this->assertSame([], Storage::disk('documents')->allFiles());
    }

    public function test_rollback_keeps_an_order_that_has_seen_activity_since_the_import(): void
    {
        $this->buildScenario();
        $this->importCommand(['--step' => ['companies', 'users', 'vehicles', 'orders']])->assertSuccessful();
        $batch = $this->batchOfLastRun();

        $order = LegacyImportMap::query()->where('entity', 'auftrag')->where('legacy_id', $this->ids['o_return'])->firstOrFail();
        DB::table('leasyback_order_audit_log')->insert(['log_id' => (string) Str::uuid(), 'order_id' => $order->target_id, 'vehicle_id' => $order->payload['vehicle_id'], 'action' => 'STATUS_CHANGED', 'changed_at' => now()]);

        $this->artisan('legacy:rollback', ['batch' => $batch, '--report-path' => $this->reports])->assertSuccessful();

        $this->assertNotNull(DB::table('leasyback_orders')->where('id', $order->target_id)->first(), 'the order with live activity stays');
        $this->assertNotNull(DB::table('vehicles')->where('vehicle_id', $order->payload['vehicle_id'])->first(), 'and so does its vehicle');
        $this->assertSame(1, DB::table('leasyback_orders')->count(), 'only that order; the rest of the batch is undone');
    }

    public function test_rollback_keeps_an_account_that_has_signed_in_since_the_import(): void
    {
        $this->buildScenario();
        $this->importCommand(['--step' => ['companies', 'users']])->assertSuccessful();
        $batch = $this->batchOfLastRun();

        $userId = DB::table('users')->where('email', 'user@alpha.example')->value('id');
        DB::table('personal_access_tokens')->insert(['tokenable_type' => 'App\Models\User', 'tokenable_id' => $userId, 'name' => 'login', 'token' => hash('sha256', 'x'), 'created_at' => now(), 'updated_at' => now()]);

        $this->artisan('legacy:rollback', ['batch' => $batch, '--report-path' => $this->reports])->assertSuccessful();

        $this->assertSame(1, DB::table('users')->count());
        $this->assertSame($userId, DB::table('users')->value('id'));
    }

    public function test_rollback_never_cascades_over_rows_the_import_did_not_create(): void
    {
        $this->buildScenario();
        $this->importCommand(['--step' => ['companies', 'users', 'vehicles']])->assertSuccessful();
        $batch = $this->batchOfLastRun();

        $alphaB2b = $this->legacyTarget('kunde', $this->ids['alpha']);
        DB::table('vehicles')->insert(['vehicle_id' => (string) Str::uuid(), 'license_plate' => 'LIVE-1', 'b2b_id' => $alphaB2b, 'vehicle_belongs' => 'B2B', 'created_at' => now(), 'updated_at' => now()]);

        $this->artisan('legacy:rollback', ['batch' => $batch, '--report-path' => $this->reports])->assertSuccessful();

        $this->assertNotNull(DB::table('b2b')->where('b2b_id', $alphaB2b)->first(), 'the company with a live vehicle is kept');
        $this->assertSame(1, DB::table('vehicles')->where('license_plate', 'LIVE-1')->count());
        $this->assertSame(0, DB::table('b2b')->where('b2b_id', $this->legacyTarget('kunde', $this->ids['gamma']))->count());
    }

    public function test_reconcile_passes_after_an_import_and_fails_when_a_target_disappears(): void
    {
        $this->buildScenario();
        $this->importCommand()->assertSuccessful();

        $this->artisan('legacy:reconcile', ['--source' => $this->export->directory, '--report-path' => $this->reports])->assertSuccessful();

        DB::table('vehicles')->where('vehicle_id', $this->legacyTarget('fahrzeug', $this->ids['v5']))->delete();

        $this->artisan('legacy:reconcile', ['--source' => $this->export->directory, '--report-path' => $this->reports])
            ->expectsOutputToContain('fahrzeug_imported_targets_exist')
            ->assertFailed();
    }

    public function test_reconcile_fails_for_source_rows_nobody_has_handled(): void
    {
        $this->buildScenario();
        $this->importCommand(['--step' => ['companies']])->assertSuccessful();

        $this->artisan('legacy:reconcile', ['--source' => $this->export->directory, '--report-path' => $this->reports])
            ->expectsOutputToContain('fahrzeug_every_source_row_has_a_map_row')
            ->assertFailed();
    }
}
