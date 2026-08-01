<?php

namespace Tests\Feature\DekraProcess;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * receiveTerminbestaetigung previously had zero authentication (only
 * rate-limiting) — unlike the TÜV SÜD webhooks, which require an API key
 * (docs/B2C_ADMIN_MIGRATION_AUDIT.md §5). Same fail-closed shared-secret
 * pattern as VerifyTuvsudApiKeyTest.
 */
class DekraWebhookSignatureTest extends TestCase
{
    use RefreshDatabase;

    private function validXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Quittung Versandweg="test" schemaVersion="1.0" ErstelltAm="2026-08-02T10:00:00">
    <AmtlichesKennzeichen>K LB 123</AmtlichesKennzeichen>
    <Beauftragungsnummer>TEST-123</Beauftragungsnummer>
    <SAPAuftragsnummer>SAP-1</SAPAuftragsnummer>
    <Vorgangsnummer>VG-1</Vorgangsnummer>
</Quittung>
XML;
    }

    public function test_webhook_is_disabled_when_no_key_is_configured(): void
    {
        Config::set('services.dekra.webhook_key', null);

        $this->call('POST', '/dekra/terminbestaetigung', [], [], [], [], $this->validXml())
            ->assertStatus(503);
    }

    public function test_webhook_rejects_a_missing_key(): void
    {
        Config::set('services.dekra.webhook_key', 'secret-key');

        $this->call('POST', '/dekra/terminbestaetigung', [], [], [], [], $this->validXml())
            ->assertStatus(401);
    }

    public function test_webhook_rejects_a_wrong_key(): void
    {
        Config::set('services.dekra.webhook_key', 'secret-key');

        // withHeader() only affects request helpers like getJson()/postJson()
        // (they apply $this->defaultHeaders internally) — the raw call()
        // used here for a non-JSON XML body doesn't, so the header has to go
        // directly into the $server array as its SERVER-superglobal name.
        $this->call('POST', '/dekra/terminbestaetigung', [], [], [], ['HTTP_X_API_KEY' => 'wrong-key'], $this->validXml())
            ->assertStatus(401);
    }

    public function test_webhook_accepts_the_correct_key_and_processes_the_payload(): void
    {
        Storage::fake('s3');
        Config::set('services.dekra.webhook_key', 'secret-key');

        $this->call('POST', '/dekra/terminbestaetigung', [], [], [], ['HTTP_X_API_KEY' => 'secret-key'], $this->validXml())
            ->assertOk();

        $this->assertDatabaseHas('quittungen', ['beauftragungsnummer' => 'TEST-123']);
    }
}
