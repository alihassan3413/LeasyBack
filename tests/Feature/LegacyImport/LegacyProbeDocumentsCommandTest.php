<?php

namespace Tests\Feature\LegacyImport;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class LegacyProbeDocumentsCommandTest extends LegacyImportTestCase
{
    private const BASE = 'https://base44.app/api/apps/0123456789abcdef0123/files/public/fedcba9876543210fedc/';

    protected function exportWithUrls(): void
    {
        $e = $this->export;
        $order = $e->add('auftrag', ['typ' => 'LEASINGRUECKGABE', 'status' => 'Abgeschlossen', 'fahrzeug_ids' => '[]']);

        foreach (['secret-ok.pdf', 'secret-moved.pdf', 'secret-login.pdf', 'secret-gone.pdf', 'secret-down.pdf'] as $name) {
            $e->add('dateianhang', ['auftrag_id' => $order, 'speicherort' => self::BASE.$name, 'dateiname' => $name]);
        }

        $e->add('dateianhang', ['auftrag_id' => $order, 'speicherort' => 'https://elsewhere.example/files/secret-other-host.pdf', 'dateiname' => 'x']);
        $e->add('dateianhang', ['auftrag_id' => 'FAHRZEUG_IMPORT_1768212572926', 'speicherort' => self::BASE.'secret-import-sheet.csv', 'dateiname' => 'x']);
        $e->add('kommentar', ['auftrag_id' => $order, 'text' => 'x', 'anhaenge' => [['name' => 'secret-comment.png', 'url' => self::BASE.'secret-ok-comment.png']]]);
        $e->add('kommentar', ['auftrag_id' => $order, 'text' => 'x', 'geloescht' => true, 'anhaenge' => [['name' => 'secret-deleted.png', 'url' => self::BASE.'secret-ok-deleted.png']]]);

        $this->export->write();
    }

    protected function fakeBase44(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();

            return match (true) {
                str_contains($url, 'cdn.other-host.example') => Http::response('PDF', 200, ['Content-Type' => 'application/pdf']),
                str_contains($url, 'secret-moved') => Http::response('', 302, ['Location' => 'https://cdn.other-host.example/blob/secret-moved.pdf']),
                str_contains($url, 'secret-login') => Http::response('', 401, ['WWW-Authenticate' => 'Bearer']),
                str_contains($url, 'secret-gone') => Http::response('missing', 404),
                str_contains($url, 'secret-down') => throw new ConnectionException('cURL error 6: could not resolve host'),
                default => Http::response('PDF', 200, ['Content-Type' => 'application/pdf']),
            };
        });
    }

    public function test_it_groups_failures_by_cause_and_never_prints_a_full_url_or_file_name(): void
    {
        $this->exportWithUrls();
        $this->fakeBase44();

        $this->artisan('legacy:probe-documents', ['--source' => $this->export->directory])
            ->expectsOutputToContain('Probing 7 document URL(s)')
            ->expectsOutputToContain('BLOCKED by our redirect protection: redirects to cdn.other-host.example and would work (HTTP 200)')
            ->expectsOutputToContain('AUTH required (HTTP 401)')
            ->expectsOutputToContain('NOT FOUND / gone (HTTP 404)')
            ->expectsOutputToContain('CONNECTION/TLS error: ConnectionException')
            ->expectsOutputToContain('BLOCKED by our host allow-list')
            ->expectsOutputToContain('cdn.other-host.example')
            ->expectsOutputToContain('application/pdf')
            ->doesntExpectOutputToContain('secret-')
            ->doesntExpectOutputToContain('0123456789abcdef0123')
            ->doesntExpectOutputToContain('fedcba9876543210fedc')
            ->assertSuccessful();
    }

    public function test_it_skips_import_spreadsheets_and_attachments_of_deleted_comments(): void
    {
        $this->exportWithUrls();
        $this->fakeBase44();

        $this->artisan('legacy:probe-documents', ['--source' => $this->export->directory, '--quiet-lines' => true])->assertSuccessful();

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'secret-import-sheet') || str_contains($r->url(), 'secret-ok-deleted'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'secret-ok-comment'));
    }

    public function test_first_probes_a_single_url_and_stores_nothing(): void
    {
        $this->exportWithUrls();
        Http::fake(['*' => Http::response('PDF', 200, ['Content-Type' => 'application/pdf'])]);

        $this->artisan('legacy:probe-documents', ['--source' => $this->export->directory, '--first' => true])
            ->expectsOutputToContain('Probing 1 document URL(s)')
            ->expectsOutputToContain('OK (HTTP 200)')
            ->assertSuccessful();

        $this->assertSame([], Storage::disk('documents')->allFiles());
        $this->assertSame(0, \DB::table('vehicle_report_documents')->count() + \DB::table('leasyback_order_attachments')->count());
    }

    public function test_a_missing_export_is_reported_not_thrown(): void
    {
        $this->artisan('legacy:probe-documents', ['--source' => $this->export->directory.'-missing'])
            ->expectsOutputToContain('Probe aborted')
            ->assertFailed();
    }
}
