# Partner API — documentation

Everything about the `/api/v1/partner` documentation surface. Internal.

## The one rule

**The partner-facing reference is generated from the code, never maintained alongside it.**

There is no hand-written copy of the endpoint list, the error codes, the abilities, the rate
limits, the retry schedule or the event catalogue. Each of those is rendered from the thing
that implements it, so an endpoint that changes shape and does not change its documentation
is a build failure rather than a support ticket.

```bash
php artisan partner:docs
```

## What is in this directory

| File | Audience | What it is |
|---|---|---|
| `README.md` | us | This file. |
| `DEPLOYMENT.md` | us | Operational requirements (queue worker, scheduler), environment, artefact locations, how to build and hand over the bundle. |
| `SHIFTMOVE_ONBOARDING.md` | us | The exact provisioning and verification sequence, sandbox through production. |
| `GO_LIVE_CHECKLIST.md` | us | Verifiable pre-flight list, plus the gaps accepted at go-live. |
| `examples/verify_signature.php` | partners | Webhook signature verification, PHP. Embedded in the reference and executed by the test suite. |
| `examples/verify-signature.cjs` | partners | The same in Node.js. `.cjs` so it loads from CommonJS and ESM projects alike. |

The partner-facing reference itself is **not** in this directory — it is generated output and
lands in `storage/app/private/partner-api-docs/`.

## Where the reference gets each part

| Part of the page | Source |
|---|---|
| Endpoints, parameters, response examples | `#[Endpoint]`, `#[QueryParam]`, `#[Response]` attributes on the Partner API controllers |
| Intro, conventions, authorization, errors | `PartnerApiDocsComposer::intro()` → Scribe's `intro_text` |
| Webhooks, documents, onboarding, changelog | `PartnerApiDocsComposer::append()` → `.scribe_partner/append.md` |
| Ability table | `PartnerAbility` |
| Company permission list | `PartnerClientProvisioner::INTEGRATION_USER_PERMISSIONS` |
| Per-endpoint scope table | walked off the registered routes and their middleware |
| Error table | `PartnerApiErrorCatalog` |
| Event catalogue, envelope, `events.json` | `PartnerEventCatalog` |
| Rate limits, idempotency, backoff, tolerances | `config/partner_api.php` |
| Signature verifiers | `examples/` in this directory, read off disk |
| Branding, output paths, OpenAPI/Postman settings | `config/scribe_partner.php` |

## What keeps it honest

`tests/Feature/PartnerApi/PartnerApiDocumentationTest.php` — 18 tests:

- the generated OpenAPI spec covers every registered partner route, and nothing else;
- every documented endpoint carries at least one response example;
- the error catalogue matches the codes the module can actually produce, **in both
  directions**, by scanning the source;
- the event catalogue matches `PartnerWebhookEvent`, in both directions;
- the published PHP and Node verifiers are *executed* against a body the real
  `PartnerWebhookDeliverer` built and a signature the real signer produced, and are checked to
  reject a tampered body, a stale timestamp and the wrong secret;
- the generated bundle contains nothing shaped like a token, a webhook secret or a filesystem
  path;
- the docs routes refuse a guest and a non-admin, and the asset route cannot be walked out of
  its directory.

## Two things not to do

**Do not hand-edit anything under `.scribe_partner/` or
`storage/app/private/partner-api-docs/`.** `partner:docs` runs Scribe with `--force` and will
discard it. If a sentence is wrong, it is wrong in `PartnerApiDocsComposer` or in a controller
attribute.

**Do not add partner routes to `config/scribe.php`.** That config documents the internal auth
module and is served from the unauthenticated `/docs` route. A partner route documented there
would be public.
