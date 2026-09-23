<?php

namespace Tests\Feature\PartnerApi;

use App\Enums\UserType;
use App\Models\User;
use App\Modules\PartnerApi\Enums\PartnerWebhookDeliveryStatus;
use App\Modules\PartnerApi\Enums\PartnerWebhookEvent;
use App\Modules\PartnerApi\Models\PartnerWebhookDelivery;
use App\Modules\PartnerApi\Models\PartnerWebhookEventRecord;
use App\Modules\PartnerApi\Services\PartnerWebhookDeliverer;
use App\Modules\PartnerApi\Services\PartnerWebhookSigner;
use App\Modules\PartnerApi\Support\PartnerApiErrorCatalog;
use App\Modules\PartnerApi\Support\PartnerEventCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Tests\Feature\PartnerApi\Concerns\BuildsPartnerClients;
use Tests\Feature\PartnerApi\Concerns\BuildsPartnerWebhooks;
use Tests\TestCase;

/**
 * The documentation is a deliverable, so it is tested like one.
 *
 * Four things are asserted here, and each of them is a failure that has
 * actually happened to somebody:
 *
 * 1. **An endpoint exists and is not documented.** The generated OpenAPI spec
 *    is compared against the routing table, so adding a partner route without
 *    documentation fails the build rather than shipping a blank spot.
 * 2. **A published error code drifts from the code.** The catalogue is compared
 *    against every code the module's exceptions, middleware and services can
 *    actually produce, in both directions.
 * 3. **The published verifier does not verify.** The PHP and Node snippets a
 *    partner will copy are *executed*, against a body the real deliverer built
 *    and a signature the real signer produced. A snippet that looks right and
 *    is wrong is worse than no snippet at all.
 * 4. **The artefact leaks something.** The generated bundle is scanned for real
 *    tokens, secrets, storage paths and non-partner routes.
 *
 * Generation runs once for the class, not once per test: it is the slowest
 * thing here by an order of magnitude and nothing in it depends on the database.
 */
class PartnerApiDocumentationTest extends TestCase
{
    use BuildsPartnerClients;
    use BuildsPartnerWebhooks;
    use RefreshDatabase;

    private const PARTNER_PREFIX = 'api/v1/partner';

    private static bool $generated = false;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->allowLocalWebhookTargets();

        if (! self::$generated) {
            Artisan::call('partner:docs');
            self::$generated = true;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Coverage
    |--------------------------------------------------------------------------
    */

    public function test_the_reference_documents_every_partner_route(): void
    {
        $documented = [];

        foreach ($this->openapi()['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                if (in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    $documented[] = $this->routeShape($method, ltrim($path, '/'));
                }
            }
        }

        sort($documented);
        $registered = $this->registeredPartnerRoutes();

        $this->assertSame(
            $registered,
            $documented,
            'The generated reference and the routing table disagree. An endpoint has been added, '
                .'removed or renamed without regenerating the docs.',
        );
    }

    public function test_the_reference_documents_nothing_but_partner_routes(): void
    {
        foreach (array_keys($this->openapi()['paths']) as $path) {
            $this->assertStringStartsWith(
                '/'.self::PARTNER_PREFIX,
                $path,
                "The partner reference documents {$path}, which is not a partner route.",
            );
        }
    }

    public function test_every_documented_endpoint_carries_at_least_one_response_example(): void
    {
        foreach ($this->openapi()['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                if (! in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    continue;
                }

                $this->assertNotEmpty(
                    $operation['responses'] ?? [],
                    strtoupper($method)." {$path} has no documented response.",
                );
            }
        }
    }

    public function test_the_bundle_contains_every_artefact_we_promise_a_partner(): void
    {
        foreach (['index.html', 'openapi.yaml', 'collection.json', 'events.json'] as $artefact) {
            $this->assertFileExists($this->docsPath($artefact));
        }

        $collection = json_decode(File::get($this->docsPath('collection.json')), true);

        $this->assertSame('LeasyBack Partner API v1', $collection['info']['name']);
    }

    /*
    |--------------------------------------------------------------------------
    | The error contract
    |--------------------------------------------------------------------------
    */

    public function test_the_error_catalogue_matches_the_codes_the_module_can_produce(): void
    {
        $documented = PartnerApiErrorCatalog::codes();
        $producible = $this->producibleErrorCodes();

        sort($documented);
        sort($producible);

        $this->assertSame(
            $producible,
            $documented,
            'PartnerApiErrorCatalog and the module disagree. Every error code is a published '
                .'contract: add it to the catalogue, or stop producing it.',
        );
    }

    public function test_no_error_code_is_listed_twice(): void
    {
        $codes = PartnerApiErrorCatalog::codes();

        $this->assertSame(array_unique($codes), $codes);
    }

    public function test_the_rendered_error_table_carries_every_code(): void
    {
        $html = File::get($this->docsPath('index.html'));

        foreach (PartnerApiErrorCatalog::codes() as $code) {
            $this->assertStringContainsString(
                $code,
                $html,
                "The generated reference does not mention the error code {$code}.",
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | The event catalogue
    |--------------------------------------------------------------------------
    */

    public function test_the_event_catalogue_describes_every_event_type_and_no_others(): void
    {
        $catalogued = array_column(PartnerEventCatalog::toArray()['events'], 'type');

        $expected = [PartnerWebhookEvent::TEST_TYPE, ...PartnerWebhookEvent::values()];

        sort($catalogued);
        sort($expected);

        $this->assertSame(
            $expected,
            $catalogued,
            'An event type exists that the catalogue does not describe, or the other way round.',
        );
    }

    public function test_the_published_event_catalogue_quotes_the_real_delivery_policy(): void
    {
        $catalogue = json_decode(File::get($this->docsPath('events.json')), true);

        $this->assertSame(
            array_values((array) config('partner_api.webhooks.backoff_seconds')),
            $catalogue['delivery']['backoff_seconds'],
        );

        $this->assertSame(
            (int) config('partner_api.webhooks.replay_tolerance_seconds'),
            $catalogue['signature']['replay_tolerance_seconds'],
        );

        $this->assertSame(
            (int) config('partner_api.webhooks.auto_disable_after_failures'),
            $catalogue['delivery']['auto_disable_after_consecutive_failed_deliveries'],
        );
    }

    /*
    |--------------------------------------------------------------------------
    | The published verifiers
    |--------------------------------------------------------------------------
    |
    | Both are run against a body the deliverer built and a signature the signer
    | produced — not a fixture. A fixture would keep passing after the recipe
    | changed, which is the only failure these tests exist to catch.
    */

    public function test_the_published_php_verifier_accepts_a_real_signed_delivery(): void
    {
        ['body' => $body, 'timestamp' => $timestamp, 'signature' => $signature, 'secret' => $secret]
            = $this->realSignedDelivery();

        require_once base_path('docs/partner-api/examples/verify_signature.php');

        $this->assertTrue(
            leasyback_verify_webhook($secret, $signature, $timestamp, $body, 300, (int) $timestamp),
            'The PHP verifier we publish does not verify a request our deliverer actually built.',
        );
    }

    public function test_the_published_php_verifier_rejects_a_tampered_body_and_a_stale_timestamp(): void
    {
        ['body' => $body, 'timestamp' => $timestamp, 'signature' => $signature, 'secret' => $secret]
            = $this->realSignedDelivery();

        require_once base_path('docs/partner-api/examples/verify_signature.php');

        $this->assertFalse(
            leasyback_verify_webhook($secret, $signature, $timestamp, $body.' ', 300, (int) $timestamp),
            'A modified body must not verify.',
        );

        $this->assertFalse(
            leasyback_verify_webhook($secret, $signature, $timestamp, $body, 300, (int) $timestamp + 301),
            'A timestamp outside the tolerance must be refused — that is the whole replay defence.',
        );

        $this->assertFalse(
            leasyback_verify_webhook('whsec_wrong', $signature, $timestamp, $body, 300, (int) $timestamp),
            'The wrong secret must not verify.',
        );
    }

    public function test_the_published_node_verifier_accepts_a_real_signed_delivery(): void
    {
        $node = $this->node();

        ['body' => $body, 'timestamp' => $timestamp, 'signature' => $signature, 'secret' => $secret]
            = $this->realSignedDelivery();

        $script = base_path('docs/partner-api/examples/verify-signature.cjs');

        $input = json_encode(compact('body', 'timestamp', 'signature', 'secret'));

        // `node -e` shifts the extra arguments down: argv[0] is the executable
        // and argv[1] is the first one we pass, not the script.
        $program = <<<'JS'
            const { verifyLeasybackWebhook } = require(process.argv[1]);
            const input = JSON.parse(process.argv[2]);
            const args = {
                secret: input.secret,
                signatureHeader: input.signature,
                timestampHeader: input.timestamp,
                rawBody: input.body,
                now: Number(input.timestamp),
            };
            const results = [
                verifyLeasybackWebhook(args),
                verifyLeasybackWebhook({ ...args, rawBody: input.body + ' ' }),
                verifyLeasybackWebhook({ ...args, now: Number(input.timestamp) + 301 }),
                verifyLeasybackWebhook({ ...args, secret: 'whsec_wrong' }),
            ];
            process.stdout.write(JSON.stringify(results));
            JS;

        $command = sprintf(
            '%s -e %s %s %s 2>&1',
            escapeshellarg($node),
            escapeshellarg($program),
            escapeshellarg($script),
            escapeshellarg((string) $input),
        );

        $output = trim((string) shell_exec($command));

        $this->assertSame(
            [true, false, false, false],
            json_decode($output, true),
            "The Node verifier we publish disagreed with a real signed delivery. Output: {$output}",
        );
    }

    /*
    |--------------------------------------------------------------------------
    | What the artefact must not contain
    |--------------------------------------------------------------------------
    */

    public function test_the_generated_bundle_leaks_no_credential_or_storage_path(): void
    {
        $prefix = (string) config('partner_api.token.prefix', 'lbp');

        foreach (['index.html', 'openapi.yaml', 'collection.json', 'events.json'] as $artefact) {
            $contents = File::get($this->docsPath($artefact));

            // A real token is the prefix plus 64 hex. The placeholder in the
            // examples is deliberately not that shape.
            $this->assertDoesNotMatchRegularExpression(
                '/'.preg_quote($prefix, '/').'_(sbx|live)_[0-9a-f]{64}/',
                $contents,
                "{$artefact} contains something shaped like a real partner token.",
            );

            $this->assertDoesNotMatchRegularExpression(
                '/whsec_[0-9a-f]{64}/',
                $contents,
                "{$artefact} contains something shaped like a real webhook secret.",
            );

            foreach ([storage_path(), base_path('app'), 'app/private/documents'] as $path) {
                $this->assertStringNotContainsString(
                    $path,
                    $contents,
                    "{$artefact} exposes a filesystem path.",
                );
            }
        }
    }

    public function test_the_reference_never_mentions_an_endpoint_this_api_does_not_have(): void
    {
        $html = File::get($this->docsPath('index.html'));

        // Two decisions recorded in §12.12.4 and §12.14.4 that a documentation
        // pass is exactly the place to quietly undo.
        $this->assertStringNotContainsString('POST /orders/{order}/status', $html);
        $this->assertStringNotContainsString('POST /offers/{offer}/accept', $html);
    }

    /*
    |--------------------------------------------------------------------------
    | Who can read it
    |--------------------------------------------------------------------------
    */

    public function test_the_docs_route_refuses_a_guest(): void
    {
        $this->get('/partner-api/docs')->assertRedirect();
        $this->get('/partner-api/openapi.yaml')->assertRedirect();
        $this->get('/partner-api/events.json')->assertRedirect();
    }

    public function test_the_docs_route_refuses_a_signed_in_non_admin(): void
    {
        $user = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $this->actingAs($user)->get('/partner-api/docs')->assertForbidden();
    }

    public function test_an_admin_can_read_the_reference_and_its_artefacts(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);

        $this->actingAs($admin)->get('/partner-api/docs')
            ->assertOk()
            ->assertSee('Partner API v1', escape: false);

        $this->actingAs($admin)->get('/partner-api/events.json')->assertOk();
        $this->actingAs($admin)->get('/partner-api/openapi.yaml')->assertOk();
        $this->actingAs($admin)->get('/partner-api/collection.json')->assertOk();
    }

    public function test_the_asset_route_cannot_be_walked_out_of_the_docs_directory(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);

        $this->actingAs($admin)->get('/partner-api/css/..%2f..%2f.env')->assertNotFound();
        $this->actingAs($admin)->get('/partner-api/etc/passwd')->assertNotFound();
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * A body the deliverer built, signed by the signer, exactly as the far end
     * would receive it.
     *
     * @return array{body: string, timestamp: string, signature: string, secret: string}
     */
    private function realSignedDelivery(): array
    {
        [$client] = $this->makeAuthenticatedPartner();

        $subscription = $this->makeSubscription($client, secret: 'whsec_'.str_repeat('ab', 32));

        $event = PartnerWebhookEventRecord::create([
            'event_id' => app(PartnerWebhookSigner::class)->generateEventId(),
            'type' => PartnerWebhookEvent::OrderStatusChanged->value,
            'api_version' => 'v1',
            'b2b_id' => $client->b2b_id,
            // Unicode and a slash on purpose: both are places a re-serialising
            // partner diverges from us, and the deliverer pins the flags that
            // decide it.
            'payload' => [
                'order' => [
                    'id' => '4b6e0a52-9c3d-4f77-8f2a-77a1c0f9b3d2',
                    'reference' => 'BXY123260806',
                    'status' => 'inspected',
                    'note' => 'Vordertür — Lackschaden a/b',
                ],
                'previous_status' => 'collected',
            ],
            'occurred_at' => now(),
        ]);

        PartnerWebhookDelivery::create([
            'partner_webhook_event_id' => $event->id,
            'partner_webhook_subscription_id' => $subscription->id,
            'status' => PartnerWebhookDeliveryStatus::Pending,
            'attempts' => 0,
            'next_attempt_at' => now(),
        ]);

        $body = app(PartnerWebhookDeliverer::class)->body($event, $subscription);
        $timestamp = (string) now()->getTimestamp();

        return [
            'body' => $body,
            'timestamp' => $timestamp,
            'signature' => app(PartnerWebhookSigner::class)
                ->sign($subscription->secret, $timestamp, $body),
            'secret' => $subscription->secret,
        ];
    }

    /**
     * Every error code the module can actually emit, found by reading the
     * source rather than by listing it a second time.
     *
     * The patterns cover the four ways a code reaches a response: the
     * PartnerApiException factories, a direct PartnerApiResponse::error(), the
     * two private helpers in AuthenticatePartner, and the status => code maps
     * the controllers hand to TranslatesServiceFailures.
     *
     * @return list<string>
     */
    private function producibleErrorCodes(): array
    {
        $codes = [];

        $patterns = [
            "/PartnerApiException::(?:invalidRequest|unauthenticated|forbidden|notFound|conflict)\(\s*'([a-z0-9_]+)'/",
            "/PartnerApiResponse::error\(\s*(?:PartnerApiResponse::)?TYPE_[A-Z_]+,\s*'([a-z0-9_]+)'/",
            "/\\\$this->(?:unauthenticated\(\s*\\\$request,\s*|forbidden\(\s*)'([a-z0-9_]+)'/",
            "/new PartnerApiException\(\s*[^,]+,\s*'([a-z0-9_]+)'/",
            "/\\\$codes\[\\\$status\] \?\? '([a-z0-9_]+)'/",
        ];

        foreach (File::allFiles(app_path('Modules/PartnerApi')) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = $file->getContents();

            foreach ($patterns as $pattern) {
                preg_match_all($pattern, $source, $matches);
                $codes = [...$codes, ...$matches[1]];
            }

            // `translatingServiceFailures([409 => 'code', …])`.
            preg_match_all("/translatingServiceFailures\(\s*\[(.*?)\]/s", $source, $maps);

            foreach ($maps[1] as $map) {
                preg_match_all("/=>\s*'([a-z0-9_]+)'/", $map, $mapped);
                $codes = [...$codes, ...$mapped[1]];
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * @return list<string>
     */
    private function registeredPartnerRoutes(): array
    {
        $routes = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! Str::startsWith($route->uri(), self::PARTNER_PREFIX.'/')) {
                continue;
            }

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $routes[] = $this->routeShape($method, $route->uri());
            }
        }

        sort($routes);

        return $routes;
    }

    /**
     * A method and a path with every parameter reduced to `{}`.
     *
     * Scribe renames URL parameters when it writes the spec — a trailing
     * `{vehicle}` becomes `{id}`, an intermediate one `{vehicle_id}` — so
     * comparing the names would only ever assert that Scribe still does that.
     * The *shape* is what matters: an endpoint added, removed, or moved to a
     * different path still fails this, which is the whole point.
     */
    private function routeShape(string $method, string $uri): string
    {
        return strtoupper($method).' '.preg_replace('/\{[^}]+\}/', '{}', $uri);
    }

    /**
     * @return array<string, mixed>
     */
    private function openapi(): array
    {
        return Yaml::parseFile($this->docsPath('openapi.yaml'));
    }

    private function docsPath(string $file): string
    {
        return base_path((string) config('scribe_partner.static.output_path')).'/'.$file;
    }

    private function node(): string
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));

        if ($node === '') {
            $this->markTestSkipped('node is not available; the Node verifier could not be executed.');
        }

        return $node;
    }
}
