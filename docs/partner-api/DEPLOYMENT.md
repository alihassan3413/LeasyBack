# Partner API — deployment and operations

Internal. Everything the Partner API needs from a server that the rest of the application
does not, plus how the documentation artefacts are produced and handed over.

The partner-facing reference is generated, not written: see
[`docs/partner-api/README.md`](README.md) for what it contains and
[`B2B_IMPLEMENTATION_HANDOFF.md` §12.18](../../B2B_IMPLEMENTATION_HANDOFF.md) for the design
record.

---

## 1. The two operational requirements

These are the only two things that will silently break the Partner API if they are missed.
Neither fails loudly — both look like "webhooks just stopped".

### 1.1 The queue worker must consume `webhooks`

```bash
php artisan queue:work --queue=webhooks,default
```

Partner webhook fan-out and delivery run on a dedicated `webhooks` queue
(`PARTNER_API_WEBHOOK_QUEUE`, default `webhooks`) so that a backlog of retries against one
dead partner endpoint cannot starve the application's own queued work. A worker that is not
told to consume it will record every event and deliver none of them, and the sweeper below
will keep re-queueing them onto a queue nobody is reading.

`deploy/supervisor/leasyback-worker.conf.template` carries the correct `--queue` value and
is installed by `provision.sh`. **On an existing server the supervisor program still holds
the old `--queue=default`**, so re-run provisioning or edit it in place:

```bash
sudo sed -i 's/--queue=default/--queue=webhooks,default/' \
  /etc/supervisor/conf.d/leasyback-worker.conf
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl restart leasyback-worker:
```

Verify:

```bash
ps aux | grep 'queue:work' | grep -c 'webhooks'   # at least 1
```

**Health check.** There is no runtime alarm for this. The cheapest one to add:

```sql
SELECT COUNT(*) FROM partner_webhook_deliveries
WHERE status = 'pending' AND created_at < NOW() - INTERVAL '15 minutes';
```

Anything above zero for a sustained period means nothing is consuming the queue. This is
open risk 1 in §12.16.15 and is still not built.

### 1.2 The scheduler must run

```
* * * * * php artisan schedule:run
```

`provision.sh` installs this for the deploy user. Three scheduled commands matter to the
Partner API:

| Command | Cadence | What breaks without it |
|---|---|---|
| `partner:webhooks:dispatch-pending` | every 5 min | Events that committed but whose dispatch was lost are never retried; overdue retries never fire. Every action it takes is idempotent, so an extra run cannot produce a duplicate webhook. |
| `partner:webhooks:emit-expired-offers` | daily 07:30 | `offer.expired` is never sent. It is the one event with no button behind it — an offer expires because a date passed, and this is its writer. `expired_notified_at` makes it exactly-once. |
| `b2b:send-offer-reminders` | daily 08:00 | Not Partner API, but shares the schedule. |

Verify:

```bash
php artisan schedule:list
crontab -u deploy -l | grep schedule:run
```

---

## 2. Environment

`.env.example` carries every key with its rationale. The ones with production consequences:

| Key | Production value | Why |
|---|---|---|
| `PARTNER_API_WEBHOOK_QUEUE` | `webhooks` | Must match the worker's `--queue`. |
| `PARTNER_API_WEBHOOK_ALLOW_INSECURE` | `false` | Plaintext webhook targets. Ignored outright when `APP_ENV=production`, but set it correctly anyway. |
| `PARTNER_API_WEBHOOK_ALLOW_PRIVATE` | `false` | Private/loopback webhook targets. Same override applies. |
| `PARTNER_API_RATE_LIMIT_PER_MINUTE` | `120` | Per-token default; a client row can override it. |
| `PARTNER_API_TOKEN_ROTATION_GRACE_MINUTES` | `0`–`60` | How long a rotated *API token* keeps working. Set it before a rotation, not during. |
| `PARTNER_API_WEBHOOK_ROTATION_GRACE_MINUTES` | `60` | How long a rotated *webhook secret* keeps verifying. |
| `PARTNER_API_DOCS_BASE_URL` | the host partners call | Appears in every generated example and in the OpenAPI `servers` entry. Unset, it falls back to `APP_URL` — which on a build machine is `http://localhost`. |

Changing any of these requires `php artisan config:cache` (the deploy script already does).

---

## 3. Migrations

The Partner API's tables ship with the ordinary migration run — no separate step:

```bash
php artisan migrate --force
php artisan migrate:status | grep partner
```

Tables: `partner_integration_clients`, `partner_api_tokens`, `partner_idempotency_keys`,
`partner_external_references`, `partner_webhook_subscriptions`, `partner_webhook_events`,
`partner_webhook_deliveries`, `partner_webhook_delivery_attempts`,
`partner_order_number_reservations`.

Nothing in this phase adds a migration.

---

## 4. Building and shipping the documentation

```bash
php artisan partner:docs
```

Composes the generated prose, writes the machine-readable event catalogue, and runs Scribe
against `config/scribe_partner.php`. Output — one self-contained, **private** directory:

| Artefact | Path |
|---|---|
| HTML reference | `storage/app/private/partner-api-docs/index.html` |
| OpenAPI 3.0.3 | `storage/app/private/partner-api-docs/openapi.yaml` |
| Postman v2.1 | `storage/app/private/partner-api-docs/collection.json` |
| Event catalogue | `storage/app/private/partner-api-docs/events.json` |
| Theme assets | `storage/app/private/partner-api-docs/{css,js,images}/` |
| Scribe intermediates | `.scribe_partner/` (git-ignored; every file is output) |

Nothing is written to `public/`. Everything under those paths is generated — never hand-edit
it, because `partner:docs` runs Scribe with `--force` and will discard the edit on the next
build.

### Reading it internally

`https://<host>/partner-api/docs`, behind `['auth', 'active', 'verified', 'admin']` — the same
gate as the `/admin` section. The two machine-readable artefacts are at
`/partner-api/openapi.yaml`, `/partner-api/collection.json` and `/partner-api/events.json`.

### Handing it to a partner

```bash
php artisan partner:docs
cd storage/app/private && zip -r ~/leasyback-partner-api-v1.zip partner-api-docs
```

The bundle opens offline: `index.html` references only its own `css/`, `js/` and `images/`
directories. Send it over the same channel as the credentials, and separately from them.

### When to rebuild

Any change to a partner route, a request or response shape, a Scribe attribute, an error
code, an event type, a rate limit, the retry schedule, or either signature example. In
practice: **every release that touches `app/Modules/PartnerApi` or `routes/partner.php`.**

`PartnerApiDocumentationTest` will fail the build if an endpoint or error code drifts from
what is documented, so a missed rebuild is caught in CI rather than by a partner. It is not
wired into `deploy.sh` on purpose: the artefact is handed over deliberately, and a deploy
that silently regenerated it would change what a partner has been sent without anybody
deciding to.

---

## 5. Provisioning a partner

```bash
php artisan partner:provision {slug} --company={b2b_id} --environment=sandbox \
    [--name=] [--user-email=] [--contact-email=] [--abilities=a,b] [--expires-in-days=] [--issued-by=]
php artisan partner:token:rotate  {slug} [--environment=] [--abilities=] [--grace-minutes=]
php artisan partner:token:revoke  {slug} [--environment=]
php artisan partner:activate      {slug} [--environment=]
php artisan partner:deactivate    {slug} [--environment=]
```

`--force` is required when the application **or** the target partner environment is
production — stricter than Laravel's default, because these get run from a staging box
pointed at the production database.

**The token is printed once, to stdout, and never logged.** It is stored only as a SHA-256
hash and is unrecoverable. If it is lost, rotate; do not go looking for it.

Deactivate ≠ revoke. Deactivating suspends reversibly and the *same* token works again after
`partner:activate`; revocation is irreversible and needs a rotation to restore access.

---

## 6. Monitoring

| Question | How |
|---|---|
| Is anything consuming `webhooks`? | The pending-delivery query in §1.1. |
| Is a partner's endpoint dead? | `partner_webhook_subscriptions.consecutive_failures`, and `is_active = false` with a `disabled_reason` at 20. |
| Are jobs failing? | `php artisan queue:failed` |
| Is a partner being rate-limited? | 429s in the access log for `/api/v1/partner/*`. A sustained rate is a conversation about their budget, not a bug. |
| Which request did a partner mean? | Every response carries `request_id`; every webhook carries `evt_…`. Both are in our logs. |

---

## 7. Rollback

The Partner API is additive. Rolling the application back does not require anything
partner-specific, with two caveats:

1. **Rolling back past a migration drops partner tables.** Subscriptions and their secrets
   go with them, and a partner would have to re-subscribe and re-deploy a new secret. Prefer
   rolling forward.
2. **A rolled-back deploy does not un-send a webhook.** Partners have already processed
   whatever was delivered. Event ids are stable, so a partner's own deduplication will absorb
   a replay, but it will not absorb a *contradictory* event.
