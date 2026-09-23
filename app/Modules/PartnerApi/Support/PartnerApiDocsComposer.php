<?php

namespace App\Modules\PartnerApi\Support;

use App\Modules\PartnerApi\Enums\PartnerAbility;
use App\Modules\PartnerApi\Services\PartnerClientProvisioner;
use App\Modules\PartnerApi\Services\PartnerWebhookSigner;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Everything in the partner reference that is not an endpoint, rendered from
 * the code that implements it.
 *
 * The failure mode this class exists to prevent is the ordinary one: an
 * endpoint changes shape, its documentation does not, and a partner finds out
 * in production. So nothing here is written twice. The scope table is walked
 * off the registered routes and their middleware; the abilities come from
 * PartnerAbility; the company permissions from PartnerClientProvisioner; the
 * rate limits, idempotency window, backoff schedule and replay tolerance from
 * `config/partner_api.php`; the error table from PartnerApiErrorCatalog; the
 * event catalogue from PartnerEventCatalog; and both signature verifiers are
 * read off disk from `docs/partner-api/examples/`, which is the same pair of
 * files the test suite executes.
 *
 * There is prose here — a reference that is only tables teaches nobody — but
 * no *fact* here is stated that the code does not also state. Changing a limit
 * changes the page on the next `php artisan partner:docs`.
 *
 * Output is Markdown, consumed by Scribe: `intro()` becomes `intro_text` (top
 * of the page, before the endpoints) and `append()` becomes
 * `.scribe_partner/append.md` (after them). Headings in both end up in the
 * sidebar.
 */
final class PartnerApiDocsComposer
{
    private const PARTNER_PREFIX = 'api/v1/partner';

    /**
     * The examples in this reference are fictional throughout — no real
     * company, vehicle, person or credential appears anywhere in the generated
     * artefacts.
     */
    private const EXAMPLE_TOKEN = 'lbp_sbx_0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcd';

    public function __construct(private readonly string $examplesPath) {}

    /**
     * Branding, orientation and every cross-cutting rule, rendered above the
     * endpoint reference.
     */
    public function intro(): string
    {
        return implode("\n\n", array_filter([
            $this->branding(),
            $this->overview(),
            $this->quickStart(),
            $this->conventions(),
            $this->authorization(),
            $this->errors(),
        ]));
    }

    /**
     * Webhooks, documents and onboarding, rendered below the endpoint
     * reference — all three are things a partner reads after they know what
     * the endpoints are.
     */
    public function append(): string
    {
        return implode("\n\n", array_filter([
            $this->webhooks(),
            $this->signatureVerification(),
            $this->webhookDelivery(),
            $this->documents(),
            $this->onboarding(),
            $this->changelog(),
        ]));
    }

    /*
    |--------------------------------------------------------------------------
    | Branding
    |--------------------------------------------------------------------------
    */

    /**
     * The default Scribe theme has no hook for a header or a palette, so the
     * branding is a style block and a banner injected through the intro. Kept
     * to one place and one screenful deliberately: this is a technical
     * reference, and a partner's engineer is here to find `X-LeasyBack-Signature`.
     */
    private function branding(): string
    {
        return <<<'HTML'
            <style>
                :root {
                    --lb-ink: #0f2a3d;
                    --lb-accent: #1f9d55;
                    --lb-rule: #dbe4ea;
                }
                .content h1 { color: var(--lb-ink); }
                .content h2 { border-bottom: 1px solid var(--lb-rule); padding-bottom: .3em; }
                .lb-banner {
                    border: 1px solid var(--lb-rule);
                    border-left: 5px solid var(--lb-accent);
                    border-radius: 6px;
                    padding: 1.1em 1.4em;
                    margin-bottom: 1.6em;
                    background: #f7fafc;
                }
                .lb-banner .lb-kicker {
                    text-transform: uppercase;
                    letter-spacing: .09em;
                    font-size: .72em;
                    color: #5b7183;
                    margin: 0 0 .35em;
                }
                .lb-banner h1 { margin: 0 0 .25em; font-size: 1.6em; border: 0; }
                .lb-banner p { margin: .2em 0 0; color: #46606f; }
                .lb-banner .lb-for { font-weight: 600; color: var(--lb-ink); }
                .lb-pill {
                    display: inline-block;
                    border: 1px solid var(--lb-accent);
                    color: var(--lb-accent);
                    border-radius: 999px;
                    padding: .1em .7em;
                    font-size: .75em;
                    margin-right: .4em;
                }
                .content table th { background: #f2f6f8; }
            </style>

            <div class="lb-banner">
                <p class="lb-kicker">LeasyBack · Partner Integration</p>
                <h1>Partner API v1</h1>
                <p><span class="lb-pill">REST</span><span class="lb-pill">JSON</span><span class="lb-pill">Webhooks</span></p>
                <p>Integration reference prepared for <span class="lb-for">Shiftmove</span>.</p>
            </div>
            HTML;
    }

    /*
    |--------------------------------------------------------------------------
    | Orientation
    |--------------------------------------------------------------------------
    */

    private function overview(): string
    {
        $prefix = (string) config('partner_api.token.prefix', 'lbp');

        return <<<MD
            ## What this API is

            A machine-to-machine integration for fleet partners. Everything a partner needs to run
            vehicle returns through LeasyBack without touching the portal:

            - register vehicles and create return orders for them;
            - follow an order's progress, either by polling its status or by reading its timeline;
            - list and download the documents produced along the way;
            - read the repair offers presented to the customer;
            - receive all of the above as signed webhooks instead of polling.

            Three properties hold everywhere and are worth reading before the endpoints:

            **Ownership is never an input.** The company whose data a request may reach comes from
            the token and from nothing else. A request carrying `b2b_id`, `user_id`, `company_id`
            or any other ownership field is refused outright rather than having it quietly ignored,
            so an integration that believes it is scoping by company learns immediately that it is not.

            **Anything you may not see does not exist.** A vehicle in another company, a consumer
            (B2C) vehicle, an unpublished draft document, an offer that was never presented — all
            of them answer `404`, never `403`. There is no way to probe for the existence of a
            record you cannot read.

            **This API is read-mostly.** You can create vehicles and orders. You cannot move an
            order's status, accept an offer or upload a document over this API — those are
            decisions made in the LeasyBack workflow, and this API reports them.

            ### Environments

            | | Base URL | Token prefix |
            |---|---|---|
            | Sandbox | `https://sandbox.leasyback.example/api/v1/partner` | `{$prefix}_sbx_…` |
            | Production | `https://app.leasyback.example/api/v1/partner` | `{$prefix}_live_…` |

            The exact hostnames for your integration are supplied with your credentials. The two
            environments are separate databases, separate tokens and separate webhook
            subscriptions; nothing crosses between them, and a sandbox token used against
            production is simply an unknown token.

            The version lives in the path, not in a header. A future `v2` is an additional path,
            and every `v1` integration keeps working unchanged.
            MD;
    }

    private function quickStart(): string
    {
        $token = self::EXAMPLE_TOKEN;

        return <<<MD
            ## Quick start

            Two calls prove a fresh credential before you write any integration code. Both require
            a valid token and **no scope at all**, so they work the moment your credential is
            issued and before any endpoint has been enabled for it.

            ```bash
            curl -sS https://sandbox.leasyback.example/api/v1/partner/health \\
              -H "Authorization: Bearer {$token}" \\
              -H "Accept: application/json"
            ```

            ```bash
            curl -sS https://sandbox.leasyback.example/api/v1/partner/me \\
              -H "Authorization: Bearer {$token}" \\
              -H "Accept: application/json"
            ```

            `GET /me` is the authoritative answer to *which company am I, and what may I call*. It
            names the integration client, the environment, the single company the token can reach
            and the exact ability set granted. If it disagrees with what you expected, stop and
            raise it — nothing else will behave the way you expect either.
            MD;
    }

    /*
    |--------------------------------------------------------------------------
    | Cross-cutting conventions
    |--------------------------------------------------------------------------
    */

    private function conventions(): string
    {
        $requestIdHeader = (string) config('partner_api.request_id.header', 'X-Request-ID');
        $requestIdMax = (int) config('partner_api.request_id.max_length', 128);
        $perMinute = (int) config('partner_api.rate_limit.per_minute', 120);
        $idempotencyHeader = (string) config('partner_api.idempotency.header', 'Idempotency-Key');
        $idempotencyTtl = (int) config('partner_api.idempotency.ttl_hours', 24);
        $idempotencyMax = (int) config('partner_api.idempotency.max_key_length', 255);
        $defaultPerPage = PartnerPagination::DEFAULT_PER_PAGE;
        $maxPerPage = PartnerPagination::MAX_PER_PAGE;

        $rejected = implode(', ', array_map(
            fn (string $key) => "`{$key}`",
            (array) config('partner_api.rejected_input_keys', []),
        ));

        return <<<MD
            ## Conventions

            ### Response envelope

            Every response — success or failure — has one of two shapes, and both carry a
            `request_id`.

            ```json
            {
              "data": { "…": "the resource" },
              "request_id": "9f1c2e4a-4c1e-4a9b-9f0e-2b1d5a7c3e11"
            }
            ```

            ```json
            {
              "error": {
                "type": "invalid_request_error",
                "code": "idempotency_key_required",
                "message": "Human-readable, and not something to branch on.",
                "details": { "…": "present on some errors only" }
              },
              "request_id": "9f1c2e4a-4c1e-4a9b-9f0e-2b1d5a7c3e11"
            }
            ```

            Branch on `error.code`. `error.type` is the coarse class, for handling a whole family
            you have no specific case for. `error.message` is prose and may be reworded at any
            time. Every code is listed under **Errors** below.

            ### Request ids

            Send `{$requestIdHeader}` and we echo it back; send nothing and we generate one. Either
            way it is on every response, including a `401`, and it is the single most useful thing
            to quote in a support request. A malformed or over-long value (more than
            {$requestIdMax} characters) is replaced rather than rejected — a bad correlation id
            must never fail a real request.

            ### Pagination

            List endpoints take `page` and `per_page` and return a `meta` block:

            ```json
            {
              "data": {
                "vehicles": [],
                "meta": { "current_page": 1, "last_page": 4, "per_page": {$defaultPerPage}, "total": 87, "from": 1, "to": {$defaultPerPage} }
              },
              "request_id": "9f1c2e4a-4c1e-4a9b-9f0e-2b1d5a7c3e11"
            }
            ```

            `per_page` defaults to {$defaultPerPage} and is **clamped** at {$maxPerPage} rather
            than rejected: asking for more than we serve is not a malformed request, and failing a
            poll loop over it would be worse than returning fewer rows. There are no `next`/`prev`
            URLs on purpose — an absolute URL built from our app URL is the wrong host for a
            partner behind a gateway. Page by number.

            ### Rate limits

            {$perMinute} requests per minute per token by default; your integration may be
            provisioned with a higher budget. Exceeding it answers `429` with `rate_limit_exceeded`
            and a `Retry-After` header, mirrored in `details.retry_after_seconds`.

            A separate, much smaller budget applies to *failed authentications* per source IP, so a
            token typo in a loop will lock the source out before it exhausts anything else.
            Refusals of type `authorization_error` (`403`) are never charged against either budget:
            a suspended integration polling `/me` must keep being told why.

            ### Idempotency

            Every endpoint that creates something requires an `{$idempotencyHeader}` header, and
            the two that update accept one. Generate a UUID per logical operation, keep it across
            your own retries, and never reuse it for a different request.

            - A completed key replays the original response for {$idempotencyTtl} hours **without
              re-running the handler** — the second call creates nothing.
            - The same key with a different payload, or against a different endpoint, answers
              `409 idempotency_key_conflict`.
            - The same key while the original is still running answers `409 idempotency_key_in_progress`.
              Retry shortly.
            - Keys are scoped to your integration. Two partners can use the same key without
              colliding, and the maximum length is {$idempotencyMax} characters.

            ### Fields we refuse

            These keys are rejected with `400 ownership_input_not_allowed` wherever they appear:
            {$rejected}. Ownership comes from the token. The refusal is deliberate — silently
            ignoring them would let an integration believe it was scoping requests when it was not.

            ### Dates and money

            Timestamps are ISO-8601. Dates without a time are `YYYY-MM-DD`. Monetary values are
            decimal **strings** in EUR, and are **net** throughout: the B2B process has no gross
            amounts, so no field carries one.
            MD;
    }

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */

    private function authorization(): string
    {
        return implode("\n\n", [
            $this->authorizationPreamble(),
            $this->abilityTable(),
            $this->companyPermissionList(),
            $this->scopeTable(),
        ]);
    }

    private function authorizationPreamble(): string
    {
        return <<<'MD'
            ## Authentication and authorization

            There is no login endpoint and no token endpoint. A credential is provisioned out of
            band, once per partner and per environment, shown exactly once, and stored by us only
            as a hash — we cannot recover it, only replace it. Send it on every request:

            ```
            Authorization: Bearer lbp_sbx_{64 hex}
            ```

            ### Two gates, both required

            Every feature endpoint applies two independent checks, in this order:

            1. **Ability** — did LeasyBack sell your integration this endpoint? This is the token's
               scope. Failing it answers `403 insufficient_scope`, with `details.required_ability`
               naming what was needed.
            2. **Company permission** — may the integration account do this inside its company?
               This is the same B2B permission system the portal uses. Failing it answers
               `403 insufficient_company_permission`, with `details.required_permission`.

            Neither gate alone is sufficient, and they are checked separately on purpose: a token
            scoped more widely than the integration account can never exceed what the company
            itself may do, and revoking a permission in the portal takes effect on the API without
            re-issuing anything.

            `GET /health` and `GET /me` deliberately require **no** ability, so a freshly issued
            credential can be verified before any endpoint is enabled for it.

            ### Token lifecycle

            | Event | What happens |
            |---|---|
            | Rotation | A new token is issued; the old one keeps working for an agreed grace window, so you deploy rather than cut over. |
            | Revocation | Immediate and irreversible. Restoring access needs a new token. |
            | Deactivation | Reversible suspension of the whole integration. The **same** token works again on reactivation. |
            | Expiry | Only if your token was issued with one. `GET /me` reports `token.expires_at`. |

            Rotation, revocation and deactivation each have their own error code, so your alerting
            can tell "we have been switched off" apart from "our secret is wrong". See **Errors**.
            MD;
    }

    private function abilityTable(): string
    {
        $list = implode("\n", array_map(
            fn (PartnerAbility $ability) => sprintf(
                '| `%s` | %s | %s |',
                $ability->value,
                $ability->group(),
                $ability->label(),
            ),
            PartnerAbility::cases(),
        ));

        return <<<MD
            ### Abilities

            The complete vocabulary. Your token carries an explicit subset, listed by `GET /me`; a
            wildcard grant is expanded there rather than shown as `*`, so what you read is always
            the real set.

            | Ability | Area | Grants |
            |---|---|---|
            {$list}

            An ability that gates no endpoint yet is still issuable, so a credential is provisioned
            once with its final scope set rather than re-issued per feature.
            MD;
    }

    private function companyPermissionList(): string
    {
        $rows = implode("\n", array_map(
            fn (string $permission) => "| `{$permission}` |",
            PartnerClientProvisioner::INTEGRATION_USER_PERMISSIONS,
        ));

        return <<<MD
            ### Company permissions

            The integration account behind your token holds exactly these, and nothing else:

            | Permission |
            |---|
            {$rows}

            No member management, no company master-data editing, no analytics. This is the second
            gate, and it is why a token can never reach data the company itself has not granted the
            integration account.
            MD;
    }

    /**
     * Walked off the registered routes rather than written out: a route that
     * gains, loses or changes a gate changes this table on the next build, and
     * an endpoint added without documentation cannot hide.
     */
    private function scopeTable(): string
    {
        $rows = [];

        foreach ($this->partnerRoutes() as $route) {
            $middleware = $route->gatherMiddleware();

            $ability = $this->middlewareArgument($middleware, 'partner.ability');
            $permission = $this->middlewareArgument($middleware, 'partner.company-can');
            $idempotency = $this->idempotencyRequirement($middleware);

            $rows[] = sprintf(
                '| `%s /%s` | %s | %s | %s |',
                implode('|', array_diff($route->methods(), ['HEAD'])),
                $this->relativeUri($route),
                $ability === null ? '—' : "`{$ability}`",
                $permission === null ? '—' : "`{$permission}`",
                $idempotency,
            );
        }

        $table = implode("\n", $rows);

        return <<<MD
            ### What each endpoint requires

            | Endpoint | Ability | Company permission | `Idempotency-Key` |
            |---|---|---|---|
            {$table}

            `GET /documents/{document}/content` is the one endpoint with no ability and no token:
            it is reached only through a short-lived signed URL, which is re-authorised on every
            fetch. See **Documents and downloads**.
            MD;
    }

    /*
    |--------------------------------------------------------------------------
    | Errors
    |--------------------------------------------------------------------------
    */

    private function errors(): string
    {
        $rows = implode("\n", array_map(
            fn (array $error) => sprintf(
                '| `%s` | %s | `%s` | %s |',
                $error['code'],
                $error['status'],
                $error['type'],
                $error['when'],
            ),
            PartnerApiErrorCatalog::all(),
        ));

        return <<<MD
            ## Errors

            Every `error.code` this API can return. This table is generated from the same catalogue
            the application validates itself against, so a code that exists in the code and not
            here — or here and not in the code — fails our build.

            **These codes are a contract.** We will add to this list; we will not change or remove
            an entry without treating it as a breaking change and telling you first. Branch on
            `code`, never on `message`.

            | Code | HTTP | Type | When |
            |---|---|---|---|
            {$rows}

            ### Handling them

            - `401` — your credential is the problem. Do not retry; the four codes tell you whether
              to look at your configuration or call us.
            - `403` — the credential is fine and the answer is no. Never retried, never rate-limited.
            - `404` — it does not exist *for you*. Do not distinguish it from "deleted".
            - `409` — a conflict with state. `idempotency_key_in_progress` is the only one worth
              retrying blindly; the others need a decision.
            - `422` — your payload. `details.fields` says which field.
            - `429` — back off for `Retry-After` seconds. Not a bug; a budget.
            - `5xx` — ours. Retry with backoff, and quote `request_id` if it persists.
            MD;
    }

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    */

    private function webhooks(): string
    {
        return implode("\n\n", [
            $this->webhookIntro(),
            $this->eventTable(),
            $this->webhookEnvelope(),
        ]);
    }

    private function webhookIntro(): string
    {
        $graceMinutes = (int) config('partner_api.webhooks.secret_rotation_grace_minutes', 60);

        return <<<MD
            # Webhooks

            Everything above can be polled. None of it has to be. A webhook subscription is the
            supported way to be told when something changes, and it is the one part of this
            integration where getting the details right matters more than getting it working —
            so the whole contract is here: the event catalogue, the envelope, the signing recipe,
            two verifiers you can copy, and the exact retry schedule.

            **We only send. There is no inbound webhook endpoint.** Anything you want to tell us
            goes through the documented write endpoints, where it is validated and authorised.

            ## Subscribing

            `POST /webhooks` with an `https` URL and the list of event types you want. The response
            carries the signing secret — **this is the only time it is ever returned.** Store it
            before you do anything else; we hold only an encrypted copy and cannot show it to you
            again. Losing it means rotating it.

            ```json
            {
              "url": "https://partner.example.com/hooks/leasyback",
              "event_types": ["order.status_changed", "offer.published", "document.available"],
              "description": "Production fleet sync"
            }
            ```

            A subscription names its event types explicitly, so a type added to this catalogue
            later never changes what an existing subscription receives. Widening is a `PATCH`.

            **Target URL rules.** The URL is the one thing you write and our servers then fetch, so
            it is checked when you save it *and again immediately before every single delivery* —
            DNS is not a property of a URL. `https` only; ports 80, 443 and 8443; no credentials in
            the URL; no host that resolves to a loopback, private, link-local or cloud-metadata
            address; and **redirects are never followed**. A `3xx` is recorded as a failed delivery
            with its status code, not as a hop.

            **Rotating the secret.** `POST /webhooks/{id}/rotate-secret` returns a new secret, once.
            The previous secret keeps verifying for {$graceMinutes} minutes
            (`previous_secret_expires_at` on the subscription), so you deploy the new one rather
            than cutting over. During the window, accept either.

            **Turning it off.** `PATCH` `is_active` to `false` to stop deliveries reversibly.
            `DELETE` is permanent and takes the delivery history with it.
            MD;
    }

    private function eventTable(): string
    {
        $catalogue = PartnerEventCatalog::toArray();

        $rows = implode("\n", array_map(
            fn (array $event) => sprintf(
                '| `%s` | %s | %s | %s |',
                $event['type'],
                $event['summary'],
                implode(', ', array_map(fn (string $key) => "`{$key}`", $event['data'])),
                $event['notes'],
            ),
            $catalogue['events'],
        ));

        $objects = implode("\n\n", array_map(
            function (string $name, array $fields): string {
                $lines = implode("\n", array_map(
                    fn (string $field, string $type) => "| `{$field}` | {$type} |",
                    array_keys($fields),
                    array_values($fields),
                ));

                return "**`{$name}`**\n\n| Field | Type |\n|---|---|\n{$lines}";
            },
            array_keys($catalogue['objects']),
            array_values($catalogue['objects']),
        ));

        $count = count($catalogue['events']) - 1;

        return <<<MD
            ## Event catalogue

            {$count} business event types, plus the test event. Every one has a real emit point in
            the workflow — nothing here is aspirational, because an event with no writer is a
            promise you would build retries around and never receive.

            A machine-readable copy of this catalogue, including the payload shapes below, ships
            with these docs as **`events.json`**. Diff it against your handler map in CI and you
            will never be surprised by an event type you do not handle.

            | Event | Means | `data` keys | Notes |
            |---|---|---|---|
            {$rows}

            Four status transitions fire **two** events: the specific one *and*
            `order.status_changed`. That is deliberate — a partner building a state machine wants
            every edge, and a partner who only cares that the appraisal is in wants one
            subscription that fires once. Subscribe to whichever suits you; subscribing to both
            means receiving both.

            ### Payload objects

            {$objects}
            MD;
    }

    private function webhookEnvelope(): string
    {
        $apiVersion = (string) config('partner_api.webhooks.api_version', 'v1');
        $eventIdHeader = PartnerWebhookSigner::HEADER_EVENT_ID;
        $timestampHeader = PartnerWebhookSigner::HEADER_TIMESTAMP;
        $signatureHeader = PartnerWebhookSigner::HEADER_SIGNATURE;

        return <<<MD
            ## The envelope

            Every event, including the test one, arrives as a `POST` with this body:

            ```json
            {
              "id": "evt_4f1c2e4a4c1e4a9b9f0e2b1d5a7c3e11",
              "type": "order.status_changed",
              "api_version": "{$apiVersion}",
              "occurred_at": "2026-08-06T09:14:02Z",
              "data": {
                "order": {
                  "id": "4b6e0a52-9c3d-4f77-8f2a-77a1c0f9b3d2",
                  "reference": "BXY123260806",
                  "status": "inspected",
                  "vehicle": { "id": "9d2c1f70-6a1a-4c2e-9f0b-1a2b3c4d5e6f", "license_plate": "B-XY 1234" }
                },
                "previous_status": "collected",
                "external_ids": { "vehicle": "FLEET-88213", "order": "RET-2026-0041" }
              }
            }
            ```

            `data.external_ids` appears only when you have registered your own ids for the records
            involved — see `external_vehicle_id` and `external_order_id` on the create endpoints.
            It is resolved per integration, so your ids are yours.

            **Payloads are notifications, not resources.** They carry ids, references and the same
            machine codes the read endpoints use, so you know *what* changed and can go read the
            current truth over an authenticated endpoint. Two exceptions earn their place: an offer
            carries its frozen snapshot, because the snapshot is what was decided and re-reading it
            later gives a different answer; and a document carries its metadata, because "a file
            exists" without knowing which file is not actionable.

            Absent everywhere, by design: internal notes, storage paths and file bytes, the
            workshop comparison, gross amounts, and billing figures.

            ### Headers

            | Header | Value |
            |---|---|
            | `{$eventIdHeader}` | `evt_{32 hex}` — **stable across every retry and replay** |
            | `{$timestampHeader}` | Unix seconds, covered by the signature |
            | `{$signatureHeader}` | `v1={hex hmac}` |
            | `Content-Type` | `application/json` |
            | `User-Agent` | `LeasyBack-Webhooks/1.0` |

            The event id is your deduplication key. It is generated once, when the event happens,
            and every attempt of every delivery of that event carries it unchanged — which is
            precisely what makes it usable as one.
            MD;
    }

    /**
     * The two verifiers, read from the files the test suite runs.
     *
     * Embedding them rather than linking to them is the point: the highest
     * support cost in this integration is a partner writing their own HMAC
     * check, and a partner who copies a working one never opens that ticket.
     */
    private function signatureVerification(): string
    {
        $tolerance = (int) config('partner_api.webhooks.replay_tolerance_seconds', 300);
        $php = $this->example('verify_signature.php');
        $node = $this->example('verify-signature.cjs');

        return <<<MD
            ## Verifying the signature

            Four steps. Step 1 is the one that gets skipped, and it is the one that makes a
            captured request unreplayable.

            1. Read `X-LeasyBack-Timestamp`. Reject anything more than **{$tolerance} seconds** from
               your clock.
            2. Compute `HMAC-SHA256(secret, "{timestamp}.{raw request body}")`, hex encoded.
            3. Compare it, **in constant time**, against the `v1=` value in `X-LeasyBack-Signature`.
            4. Use `X-LeasyBack-Event-ID` to discard events you have already processed.

            Step 2 says *raw* body. Re-serialising the parsed JSON will not match — key order and
            unicode escaping are ours, not yours. In Express that means `express.raw()` on the
            webhook route, not `express.json()`.

            The timestamp is inside the signed material deliberately. Signing the body alone would
            let anyone who captured one request replay it forever; because the timestamp is
            covered, a replayer cannot move it forward without invalidating the signature, which is
            what turns step 1 into a real defence rather than a decorative one. The `v1=` prefix is
            a version marker: if the scheme ever changes we will send both during the transition,
            so split on `,` and match the prefix.

            Both verifiers below are executed by our own test suite against a body built by the
            real deliverer. They are not illustrations.

            ### PHP

            ```php
            {$php}
            ```

            ### Node.js

            Save it as `verify-signature.cjs`. The `.cjs` extension is deliberate: it stays
            CommonJS whatever the surrounding project declares, so `require()` works from a
            CommonJS project and `import { verifyLeasybackWebhook } from './verify-signature.cjs'`
            works from an ESM one.

            ```javascript
            {$node}
            ```
            MD;
    }

    private function webhookDelivery(): string
    {
        /** @var list<int> $backoff */
        $backoff = (array) config('partner_api.webhooks.backoff_seconds', []);
        $timeout = (int) config('partner_api.webhooks.timeout_seconds', 10);
        $connectTimeout = (int) config('partner_api.webhooks.connect_timeout_seconds', 5);
        $autoDisable = (int) config('partner_api.webhooks.auto_disable_after_failures', 20);
        $attempts = count($backoff) + 1;

        $rows = ['| 1 | immediately | — |'];
        $cumulative = 0;

        foreach ($backoff as $index => $seconds) {
            $cumulative += $seconds;
            $rows[] = sprintf(
                '| %d | %s after the previous attempt | ≈ %s after the event |',
                $index + 2,
                $this->humanSeconds($seconds),
                $this->humanSeconds($cumulative),
            );
        }

        $table = implode("\n", $rows);
        $total = $this->humanSeconds($cumulative);

        return <<<MD
            ## Delivery, retries and failure

            We consider a delivery successful on **any `2xx`**. Anything else — including a `3xx`,
            which we do not follow — is a failure and is retried on this schedule:

            | Attempt | When | Elapsed |
            |---|---|---|
            {$table}

            {$attempts} attempts over roughly {$total}. The schedule is a fixed table, not
            arithmetic, so it can be quoted to you exactly. After the last attempt the delivery is
            `exhausted` and nothing further happens without a manual replay.

            We allow **{$timeout} seconds** for a response and **{$connectTimeout} seconds** to
            connect. Answer fast and do the work afterwards: a handler that processes inline and
            takes eleven seconds will be retried while it is still succeeding, which is how
            duplicates happen. Deduplicate on the event id and return `200` immediately.

            ### When your endpoint is down

            After **{$autoDisable} consecutive failed deliveries** the subscription is suspended
            automatically — deliveries, not attempts, so it is measured in events missed rather
            than in how hard we retried. It is suspended, never deleted: your URL, your secret and
            your delivery history all survive. `GET /webhooks/{id}` reports `disabled_reason` and
            `disabled_at`, so you can find out without a support ticket. `PATCH` `is_active` back
            to `true` when you have fixed it; that also clears the failure counter, so a repaired
            endpoint starts from zero.

            Any single success clears the counter. It measures "is this endpoint dead", not "has
            this endpoint ever failed".

            ### Debugging a failure

            `GET /webhooks/{id}/deliveries?status=failed` lists what went wrong, newest first. Each
            row carries every attempt, the status code we saw, and a bounded excerpt of what your
            server actually returned — which is usually enough to identify a framework error page
            without instrumenting anything. `GET /webhooks/{id}/deliveries/{delivery}` adds the
            exact payload we sent, so a failing signature check can be reproduced against the real
            bytes.

            An attempt with `"blocked": true` and no status code never left our process: the target
            failed the URL check at delivery time. That is almost always DNS — a hostname that
            answered a public address when you subscribed now answers a private one.

            `POST /webhooks/{id}/deliveries/{delivery}/replay` re-queues a failed delivery with the
            same event id and the same body. A delivery that already succeeded is refused, because
            replaying it would send you a duplicate. The attempt counter is not reset: it records
            how many times your endpoint has been called with these bytes.

            ### Ordering and duplicates

            Events are delivered independently and are **not ordered**. Two events for the same
            order can arrive out of sequence, and a retried event can arrive after a newer one.
            Use `occurred_at` if sequence matters to you, and treat every handler as idempotent —
            at-least-once delivery is what a retry policy means.
            MD;
    }

    /*
    |--------------------------------------------------------------------------
    | Documents
    |--------------------------------------------------------------------------
    */

    private function documents(): string
    {
        return <<<'MD'
            # Documents and downloads

            Documents are the one place where "read the API" and "get the bytes" are two different
            operations, so it is worth being explicit about why.

            **Everything is on private storage.** No response from this API contains a storage path,
            and no partner-visible URL points at a disk. There is nothing to guess and nothing to
            enumerate.

            **Downloading is two steps.**

            1. `GET /documents/{document}/download` — returns a **signed URL** valid for 30
               minutes, plus the filename and content type. It does not return the file.
            2. `GET` that URL — streams the bytes.

            The second request carries **no bearer token**, and that is the point: the link can be
            handed to something that is not your integration — a browser, a document pipeline, a
            support tool — without also handing over a credential that unlocks every other
            endpoint.

            The signature is not the whole check. The link embeds the integration client it was
            minted for, and every fetch re-asks whether that client is still active and whether the
            document still belongs to a B2B vehicle in its company *before a single byte is read*.
            Deactivating an integration therefore invalidates every outstanding link immediately,
            not thirty minutes later.

            A tampered or unsigned link answers `403 download_link_invalid`; an expired one answers
            `403 download_link_expired`. Mint a new one — they are cheap. Do not cache the signed
            URL; cache the document id and mint on demand.

            **What you can see.** Published LeasyBack paperwork for the order (`source: report` —
            appraisals, re-appraisals, invoices, collection and return files) and your own company's
            documents on the vehicle (`source: vehicle`). Unpublished drafts and internal inspection
            material are filtered out of the queries themselves, not after the fact, so they cannot
            be listed, shown, linked or streamed at any stage.

            The `document.available` webhook fires on **publication**, not upload — so an event you
            receive is always for a document you can actually download.

            Responses carrying a document are served with `Cache-Control: private, no-store`. It is
            one company's paperwork behind a short-lived link, and nothing in front of it should
            keep a copy.
            MD;
    }

    /*
    |--------------------------------------------------------------------------
    | Onboarding
    |--------------------------------------------------------------------------
    */

    private function onboarding(): string
    {
        $prefix = (string) config('partner_api.token.prefix', 'lbp');

        return <<<MD
            # Onboarding

            The order below is the one we run, and every step is verifiable before the next one
            matters. Nothing here needs real fleet data until stage 4.

            ## 1. Sandbox credentials

            You receive, from your LeasyBack contact and over a secure channel:

            | | |
            |---|---|
            | Sandbox base URL | `https://sandbox.leasyback.example/api/v1/partner` |
            | Bearer token | `{$prefix}_sbx_…` — shown once, never recoverable |
            | Granted abilities | the exact list, matching what `GET /me` will report |
            | Company | the single sandbox company your token can reach |

            Store the token as you would a password. It is not recoverable: if it is lost, it is
            rotated, not resent.

            ## 2. Verify the credential

            Three calls, in this order, before writing any integration code.

            ```bash
            # 1. The token is valid, active, and in the environment you expect.
            curl -sS "\$BASE/health" -H "Authorization: Bearer \$TOKEN" -H "Accept: application/json"

            # 2. The identity behind it. Check `company` and `token.abilities`
            #    against what you were told — if they disagree, stop here.
            curl -sS "\$BASE/me" -H "Authorization: Bearer \$TOKEN" -H "Accept: application/json"

            # 3. A subscription, and the secret. This is the only time the secret is returned.
            curl -sS -X POST "\$BASE/webhooks" \\
              -H "Authorization: Bearer \$TOKEN" \\
              -H "Content-Type: application/json" \\
              -H "Idempotency-Key: \$(uuidgen)" \\
              -d '{"url":"https://partner.example.com/hooks/leasyback","event_types":["order.status_changed"]}'
            ```

            ## 3. Verify the webhook path end to end

            ```bash
            curl -sS -X POST "\$BASE/webhooks/\$WEBHOOK_ID/test" \\
              -H "Authorization: Bearer \$TOKEN" -H "Accept: application/json"
            ```

            This queues a signed `webhook.test` event through the identical delivery path a real
            event uses. It carries no business data and is sent regardless of which types your
            subscription selected — its entire purpose is to let you prove your endpoint and your
            signature check work before any real event exists.

            Then confirm it from our side:

            ```bash
            curl -sS "\$BASE/webhooks/\$WEBHOOK_ID/deliveries" \\
              -H "Authorization: Bearer \$TOKEN" -H "Accept: application/json"
            ```

            A `succeeded` delivery here means: your URL passed the target checks, your TLS is
            valid, your endpoint answered `2xx` inside the timeout. If it says `failed`, the
            response excerpt on the attempt will usually tell you why without any instrumentation
            on your side.

            **Do not proceed until a test event verifies.** A signature check that has never
            verified a real request is the single most common cause of a stalled integration.

            ## 4. Exercise the business flow in sandbox

            In roughly this order, all against fictional data:

            1. `POST /vehicles` — create a vehicle. Send your own `external_vehicle_id` if you have
               one; it is permanent and lets you look the vehicle up by your id afterwards.
            2. `POST /vehicles/{vehicle}/orders` — create the return order. Both creates require an
               `Idempotency-Key`; retry one with the same key and confirm you get the same record
               back and not a second one.
            3. `GET /orders/{order}/status` and `/timeline` — poll while we move the order through
               its stages, and confirm each transition also arrives as a webhook.
            4. `GET /orders/{order}/documents` and `/documents/{id}/download` — fetch a document
               through the signed link.
            5. `GET /orders/{order}/offers` — read a presented offer.

            Confirm as you go that your handler is idempotent (replay a delivery and check nothing
            doubles), that you tolerate the specific-plus-generic event pair, and that a `429`
            makes you back off rather than retry immediately.

            ## 5. Production

            A separate credential for a separate database. Nothing carries over — not the token,
            not the webhook subscription, not the data.

            1. Confirm with your LeasyBack contact which abilities the production token needs. It
               is normal for it to be narrower than sandbox.
            2. Receive the production token (`{$prefix}_live_…`) and base URL.
            3. Re-run stage 2 against production: `/health`, then `/me`. Check the environment
               field says `production` and the company is the right one.
            4. Create the production webhook subscription and store its secret. **It is a different
               secret**; configure it per environment, never share one between them.
            5. Send a test event and confirm a `succeeded` delivery, exactly as in stage 3.
            6. Go live with one real vehicle before the rest. The first real order is worth
               watching end to end.

            ## Support

            Quote the `request_id` from the failing response. For a webhook problem, quote the event
            id (`evt_…`) and the delivery id. Both identify the exact request in our logs, which
            turns most questions into an answer rather than an investigation.
            MD;
    }

    private function changelog(): string
    {
        return <<<'MD'
            # Changelog

            Dates are the release dates of the API, not of this document.

            ### v1 — 2026-08-06

            First public version. Everything in this reference ships together:

            - **Authentication** — bearer tokens, per partner and per environment; the two-gate
              ability + company-permission model; per-token and per-IP rate limits; request ids on
              every response.
            - **Vehicles** — create, update, read and list, with permanent external-id mapping.
            - **Orders** — create a return order for a vehicle; read and list orders; status and
              15-stage timeline.
            - **Documents** — list, read metadata, and download through short-lived signed links.
            - **Offers** — read presented offers with their frozen snapshots. Read-only: offers are
              accepted in the LeasyBack portal.
            - **Webhooks** — subscriptions, HMAC-SHA256 signing with rotation grace, 18 event types,
              a fixed retry schedule, delivery history and replay.

            #### Compatibility promise

            Additive changes ship without notice, and your integration must tolerate them:

            - new fields in a response object;
            - new event types in the catalogue (your subscription's type list is explicit, so you
              will not receive one you did not ask for);
            - new `error.code` values within an existing HTTP status and type.

            These are breaking, and will be announced with a migration window:

            - removing or renaming a field, an endpoint or an `error.code`;
            - changing the HTTP status an existing `error.code` is returned with;
            - changing the shape of a webhook payload. The envelope carries `api_version` for
              exactly this reason — a breaking payload change is a new value there, not a silent
              edit.

            Parse leniently: ignore fields you do not recognise rather than rejecting the response.
            MD;
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * @return list<RoutingRoute>
     */
    private function partnerRoutes(): array
    {
        $routes = array_filter(
            Route::getRoutes()->getRoutes(),
            fn (RoutingRoute $route) => Str::startsWith($route->uri(), self::PARTNER_PREFIX.'/'),
        );

        usort($routes, fn (RoutingRoute $a, RoutingRoute $b) => [$this->relativeUri($a), $a->methods()[0]]
            <=> [$this->relativeUri($b), $b->methods()[0]]);

        return array_values($routes);
    }

    private function relativeUri(RoutingRoute $route): string
    {
        return Str::after($route->uri(), self::PARTNER_PREFIX.'/');
    }

    /**
     * @param  list<string>  $middleware
     */
    private function middlewareArgument(array $middleware, string $name): ?string
    {
        foreach ($middleware as $entry) {
            if (Str::startsWith($entry, $name.':')) {
                return Str::after($entry, ':');
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $middleware
     */
    private function idempotencyRequirement(array $middleware): string
    {
        foreach ($middleware as $entry) {
            if ($entry === 'partner.idempotent') {
                return 'optional';
            }

            if (Str::startsWith($entry, 'partner.idempotent:')) {
                return Str::after($entry, ':') === 'required' ? '**required**' : 'optional';
            }
        }

        return '—';
    }

    /**
     * A verifier, read verbatim off disk.
     *
     * Interpolated into a fenced block. PHP strips a heredoc's own indentation
     * at parse time and leaves an interpolated value alone, so the file's own
     * columns survive — which is what a partner is going to paste.
     */
    private function example(string $filename): string
    {
        $path = $this->examplesPath.DIRECTORY_SEPARATOR.$filename;

        if (! is_file($path)) {
            return "// {$filename} is missing from the examples directory.";
        }

        return mb_rtrim((string) file_get_contents($path));
    }

    private function humanSeconds(int $seconds): string
    {
        return match (true) {
            $seconds < 60 => "{$seconds}s",
            $seconds < 3600 => floor($seconds / 60).'m',
            default => round($seconds / 3600, 1).'h',
        };
    }
}
