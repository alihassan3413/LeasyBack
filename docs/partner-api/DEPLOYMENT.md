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

This preview is unchanged by §4a below and stays admin-only. It is the copy we read; `/api-docs`
is the copy a partner reads.

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

## 4a. Serving the reference to partners at `/api-docs`

A partner needs to read the reference without a LeasyBack account. The zip in §4 covers a
one-off handover; this covers the standing URL, which is what a partner actually links their
engineers at.

**`{APP_URL}/api-docs/`, HTTP Basic Auth, served by nginx straight out of
`storage/app/private/partner-api-docs`.**

Deliberately not a Laravel route: no new login system, no `users` row, no B2B permission, no
migration. nginx already terminates TLS in front of the app and already knows how to serve a
static tree; the whole feature is an `alias` and an `auth_basic`. The application is not in the
request path at all, so nothing here can change Partner API behaviour.

The docs sit on the **existing domain**, alongside — and separate from — the API itself. All
three URLs below are derived from configuration, not chosen: substitute your own host.

| | URL | Derived from | Who uses it | Credential |
|---|---|---|---|---|
| Reference (external) | `{APP_URL}/api-docs/` | `APP_URL` | a partner's engineers, in a browser | Basic Auth username/password |
| Reference (internal) | `{APP_URL}/partner-api/docs` | `APP_URL` | LeasyBack staff | their admin login |
| The API | `{PARTNER_API_DOCS_BASE_URL}/api/v1/partner` | `PARTNER_API_DOCS_BASE_URL`, falling back to `APP_URL` | the partner's software | `Authorization: Bearer lbp_…` |

Concretely:

| | Local default | Production |
|---|---|---|
| Reference (external) | — (nginx only; use the internal preview locally) | `https://YOUR-ACTUAL-DOMAIN/api-docs/` |
| Reference (internal) | `http://127.0.0.1:8001/partner-api/docs` | `https://YOUR-ACTUAL-DOMAIN/partner-api/docs` |
| The API | `http://127.0.0.1:8001/api/v1/partner` | `https://YOUR-ACTUAL-DOMAIN/api/v1/partner` |

The local port is whatever `APP_URL` says; `http://127.0.0.1:8001` assumes
`php artisan serve --port=8001` (Laravel's own default is 8000).

**On the two config keys.** `APP_URL` is the application host, and both documentation URLs are
just paths on it — nginx serves `/api-docs` from the same server block, so there is no separate
key for it and nothing in Laravel ever generates that URL.

`PARTNER_API_DOCS_BASE_URL` is named for the docs but is **not** the URL of the documentation
site: it is the **API host baked into the generated documentation** — every `curl` example and
the OpenAPI `servers` entry (`config/scribe_partner.php:73`). Setting it to
`https://YOUR-ACTUAL-DOMAIN/api-docs` would put `/api-docs` into every example a partner copies
and break all of them. It takes the API host, plain: `https://YOUR-ACTUAL-DOMAIN`. Unset, it
falls back to `APP_URL`, which on a build machine is `http://localhost` — see §2.

There is no `PARTNER_API_BASE_URL` key. The API's own URL is `APP_URL` plus the fixed
`/api/v1/partner` prefix from `routes/partner.php`; `PARTNER_API_DOCS_BASE_URL` exists only to
override the host in the *generated* artefacts when the build machine is not the serving host.

### 4a.1 What nginx serves

Everything `partner:docs` generates, from where it generates it:

```
index.html   css/   js/   images/   openapi.yaml   collection.json   events.json
```

Nothing is copied into `public/`. `alias` points nginx at the private directory; the deploy user
owns it `deploy:www-data` with group-read (`provision.sh` sets this), which is all nginx needs.

Four details in the config that are not cosmetic:

- **`location = /api-docs` returns a 301 to `/api-docs/`.** Scribe's bundle links assets
  relatively (`./css/…`), and a browser resolves those against the *parent* of the current path.
  Without the trailing slash that parent is `/`, and every asset 404s against the site root.
- **The trailing slash is on both the location prefix and the alias.** A prefix without one
  against an alias with one is the classic nginx alias traversal — `/api-docs../` concatenates
  outside the docs root. Matching slash to slash is the fix.
- **The prefix is `^~`.** That stops nginx considering the regex locations underneath it, in
  particular `location ~ \.php$`. The bundle contains no `.php` file; this makes executing one
  impossible if it ever did.
- **`openapi.yaml` gets an exact-match location.** `.yaml` is absent from nginx's default
  `mime.types`, so it would otherwise be served as `application/octet-stream` and download
  rather than render. `.json`, `.css`, `.js`, `.svg` and `.png` are already correct.

Path traversal needs no application-level check here: nginx decodes `%`-escapes and collapses
`..` while normalising the request URI, *before* it picks a location, so `/api-docs/../../.env`
is normalised to `/.env` and never reaches the docs block.

### 4a.2 Template

`deploy/nginx/partner-api-docs.conf.template` → installed as
`/etc/nginx/snippets/leasyback-partner-api-docs.conf`, pulled into the site by an `include` in
`deploy/nginx/leasyback.conf.template`. `__APP_DIR__` and `__DOMAIN__` are substituted at
install time.

`provision.sh` does all of this on a fresh server, including creating an **empty**
`.partner-docs-users`. That is on purpose: nginx does not check `auth_basic_user_file` at
config-test time and returns a 500 per request if it is missing, so the empty file makes
`/api-docs` fail *closed* with a 401 until a user is deliberately added.

### 4a.3 Setup on an existing server

Run once, as root. Set both variables to your own values — they are the same `APP_DIR` and
`DOMAIN` as `deploy/config.sh`, and `DOMAIN` must match the host in `APP_URL`.

```bash
APP_DIR=/var/www/LeasyBack
DOMAIN=YOUR-ACTUAL-DOMAIN          # e.g. the host part of APP_URL, no scheme

# Read it back from the app rather than retyping it, if the app is already configured:
#   DOMAIN=$(sudo -u deploy php "${APP_DIR}/artisan" tinker \
#             --execute 'echo parse_url(config("app.url"), PHP_URL_HOST);')

# 1. Generate the docs (as the deploy user, from the app directory).
sudo -u deploy php "${APP_DIR}/artisan" partner:docs

# 2. Install the nginx snippet.
sudo mkdir -p /etc/nginx/snippets
sudo sed -e "s|__APP_DIR__|${APP_DIR}|g" -e "s|__DOMAIN__|${DOMAIN}|g" \
    "${APP_DIR}/deploy/nginx/partner-api-docs.conf.template" \
    | sudo tee /etc/nginx/snippets/leasyback-partner-api-docs.conf >/dev/null

# 3. Create the Basic Auth file and the first partner user.
#    -c CREATES the file and TRUNCATES it — only ever on the first user.
sudo apt-get install -y apache2-utils
sudo htpasswd -c /etc/nginx/.partner-docs-users shiftmove-docs
sudo chown root:www-data /etc/nginx/.partner-docs-users
sudo chmod 640 /etc/nginx/.partner-docs-users

# 4. Check whether the include is already present.
grep -n partner-api-docs /etc/nginx/sites-available/leasyback.conf
```

If step 4 printed a line, a current `provision.sh` already wrote the `include` — skip to step 5.
If it printed nothing, add it **by hand**:

```bash
sudo cp /etc/nginx/sites-available/leasyback.conf ~/leasyback.conf.bak
sudo nano /etc/nginx/sites-available/leasyback.conf
```

Add this one line inside the server block, next to the other `location` directives:

```nginx
    include /etc/nginx/snippets/leasyback-partner-api-docs.conf;
```

Edit by hand rather than with `sed -i`: once certbot has run there are **two** server blocks —
the `listen 443 ssl` one that carries the real configuration, and a minimal `:80` block that only
redirects. The line belongs in the `443` block, and a scripted insert cannot see which one it
landed in. Position within the block does not matter; the docs locations are `^~` and `=`, so
nginx picks them by specificity, not by order.

```bash
# 5. Test and reload.
sudo nginx -t
sudo systemctl reload nginx
```

**Verify before telling a partner it exists:**

```bash
BASE="https://${DOMAIN}"   # same DOMAIN as above; the host from APP_URL

curl -sS -o /dev/null -w '%{http_code}\n' "${BASE}/api-docs/"                 # 401
curl -sS -u shiftmove-docs:PASS -o /dev/null -w '%{http_code}\n' \
     "${BASE}/api-docs/"                                                      # 200
curl -sS -u shiftmove-docs:PASS -I "${BASE}/api-docs/openapi.yaml" \
     | grep -i content-type                                                   # application/yaml
curl -sS -u shiftmove-docs:PASS -o /dev/null -w '%{http_code}\n' \
     "${BASE}/api-docs/images/leasyback-logo.svg"                             # 200
curl -sS -o /dev/null -w '%{http_code}\n' "${BASE}/partner-api/docs"          # 302 (still admin-only)
```

The last one matters: it confirms adding `/api-docs` did not open the internal preview.

### 4a.4 HTTPS

Nothing extra. Certbot's nginx installer rewrites the existing server block to `listen 443 ssl`
and adds the `:80` → `:443` redirect, and the `include` travels with it — the docs locations are
inside the same server block. If TLS is not on the domain yet:

```bash
sudo certbot --nginx -d "${DOMAIN}"
sudo nginx -t && sudo systemctl reload nginx
```

Basic Auth over plain HTTP sends the password in base64 in every request, so do not hand out the
URL until the certificate is in place. Confirm with the `401` curl above returning over `https://`.

### 4a.5 Adding and removing partner users

One login per partner organisation, named for them. Never share one across partners — removal is
then all-or-nothing.

```bash
# Add (or change the password of) a user. NO -c: that would truncate the file
# and delete every other partner's login.
sudo htpasswd /etc/nginx/.partner-docs-users shiftmove-docs

# Remove a user.
sudo htpasswd -D /etc/nginx/.partner-docs-users shiftmove-docs

# List who has access.
cut -d: -f1 /etc/nginx/.partner-docs-users
```

No reload is needed for any of these — nginx opens the file per request. That also means a
removal takes effect on the partner's next request, immediately.

### 4a.6 Updating the docs after an API change

```bash
sudo -u deploy php /var/www/LeasyBack/artisan partner:docs
```

That is the whole update. nginx serves the directory the command just rewrote, and the config
sends `Cache-Control: private, no-store`, so the next request gets the new build — no reload, no
cache purge, no deploy.

Rebuild on any change to a partner route, a request or response shape, a Scribe attribute, an
error code, an event type, a rate limit, the retry schedule, or either signature example. In
practice, every release touching `app/Modules/PartnerApi` or `routes/partner.php`.
`PartnerApiDocumentationTest` fails the build if the docs drift from the code, so a missed
rebuild is caught in CI rather than by a partner.

`partner:docs` stays out of `deploy.sh` for the reason in §4: the artefact is published
deliberately. Run it as a step of the release, not as a side effect of one.

### 4a.7 Three credentials, three purposes

The most common way this goes wrong is somebody treating the docs login as *the* integration
credential. It is not related to it.

| Credential | For | Looks like | Issued by | Rotated by |
|---|---|---|---|---|
| **Docs Basic Auth** | a human reading documentation in a browser | `shiftmove-docs` + password | `htpasswd` on the server | `htpasswd` again (§4a.5) |
| **Partner API Bearer token** | the partner's software calling `/api/v1/partner` | `lbp_sbx_…` / `lbp_…` | `partner:provision` (§5) | `partner:token:rotate` |
| **Webhook signing secret** | verifying webhooks *we* send *them* | returned once by `POST /webhooks` | the partner, over the API | `POST /webhooks/{id}/rotate-secret`, by them |

Consequences worth stating plainly:

- Revoking the docs login does not stop API traffic. Revoking the API token does not close the
  docs. They are independent on purpose — a partner can lose documentation access during a
  contract review without their integration going down, and vice versa.
- We hold no usable copy of the webhook signing secret. If a partner loses it, they rotate it
  themselves; we cannot read it out.
- **A partner never receives a LeasyBack Admin account.** Not for the docs, not for support, not
  temporarily. The internal preview at `/partner-api/docs` is behind the same gate as `/admin`,
  which is the entire staff surface — the Basic Auth URL exists precisely so that granting docs
  access never means granting that. Shiftmove included.
- Send the three over separate channels, and never in the same message as each other.

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

### 5.1 Issuing a Bearer token on the server, step by step

Two identifiers are involved and they are not the same thing. `{slug}` is the **partner client**
slug — a short name we choose for the integration, e.g. `shiftmove`. `--company` is the
**`b2b_id`** of the LeasyBack company the integration acts for. Getting these the wrong way round
is the most common failure, and it fails loudly (`No B2B company with b2b_id '…'`).

**1. Create the B2B company first.** The integration acts *for* a company; there is nothing to
bind a token to until it exists. Create it through the admin panel as normal. It must be active —
provisioning refuses a deactivated company, because its tokens would be refused anyway.

**2. Confirm its `b2b_id`.** On the server, from the app directory:

```bash
php artisan tinker --execute 'foreach (\App\Modules\UserProfile\B2B\Models\B2B::query()
    ->where("company_name", "like", "%Shiftmove%")->get() as $c) {
        echo $c->b2b_id, "  ", $c->company_name, "  active=", (int) $c->is_active, PHP_EOL;
    }'
```

Read the name back before using the id. In production this is the step where somebody confirms
they are pointing at the production company and not the sandbox one.

**3. Provision.**

```bash
php artisan partner:provision shiftmove \
    --company={b2b_id from step 2} \
    --environment=production \
    --name="Shiftmove" \
    --contact-email={their integration contact} \
    --abilities={the agreed set} \
    --issued-by={your name} \
    --force
```

One run creates the dedicated integration user, its company membership, the client row and the
first token. Grant abilities deliberately rather than defaulting to all — see
[`SHIFTMOVE_ONBOARDING.md`](SHIFTMOVE_ONBOARDING.md) stage 1 for the starting set.

**4. Copy the token now.** It is printed once, to stdout. It is stored as a SHA-256 hash and
cannot be recovered — not from the database, not from the logs, not by us. If you close the
terminal without copying it, the fix is `partner:token:rotate`, not a support ticket.

**5. Share it separately and securely.** Over a channel the partner controls, in a message that
contains nothing else — not the docs URL, not the Basic Auth password, not the zip. See §4a.7 for
why the three credentials stay apart.

Then hand over, in separate messages:

| Item | Value |
|---|---|
| API base URL | `https://YOUR-ACTUAL-DOMAIN/api/v1/partner` — must match `PARTNER_API_DOCS_BASE_URL` (§4a), or the examples they copy out of the reference will point somewhere else |
| Bearer token | `lbp_…` (step 4) |
| Documentation URL | `https://YOUR-ACTUAL-DOMAIN/api-docs/` |
| Documentation login | the `htpasswd` user from §4a.3 |

Confirm the API base URL against the shipped artefact rather than from memory — it is whatever
was baked in at build time:

```bash
grep -A1 '^servers:' storage/app/private/partner-api-docs/openapi.yaml
```

A build made where `PARTNER_API_DOCS_BASE_URL` was unset reads `url: 'http://localhost'`. That
bundle must not go to a partner — set the key and re-run `partner:docs`.

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
