# Shiftmove onboarding — the exact flow

Internal runbook. The partner-facing version of stages 2–5 is generated into the reference
itself (`php artisan partner:docs` → **Onboarding**); this file is our side: what we do, in
what order, and what we check before moving on.

Shiftmove is a database row — an integration client — not a code branch. Nothing in the
application names them, and every step below is the same for the next partner.

---

## Before anything

Two things must exist and neither is ours to create alone:

| Prerequisite | Owner | Notes |
|---|---|---|
| A LeasyBack B2B company record for Shiftmove's fleet | LeasyBack | `b2b_id` is the `--company` argument below. Must be active. |
| An HTTPS endpoint Shiftmove will receive webhooks on | Shiftmove | Public, valid certificate, port 80/443/8443. It does not have to work yet — stage 3 is where it is proven. |

---

## Stage 1 — Sandbox provisioning (LeasyBack)

```bash
php artisan partner:provision shiftmove \
    --company={sandbox_b2b_id} \
    --environment=sandbox \
    --name="Shiftmove" \
    --contact-email={their integration contact} \
    --abilities=vehicles.read,vehicles.write,orders.read,orders.write,timeline.read,documents.read,offers.read,webhooks.read,webhooks.manage
```

Decide the ability set deliberately rather than granting everything. The starting set above is
the full read surface plus vehicle/order creation plus webhook management — what an integration
that files returns and tracks them needs, and nothing more. `offers.accept` and
`documents.write` gate no endpoint today (§12.6) and should not be granted "for later".

Capture from the command output, **once**:

- the plaintext token (`lbp_sbx_…`) — it is never recoverable;
- the client slug and the company it is bound to.

Send to Shiftmove, over a secure channel, separately from the documentation bundle:

| Item | Value |
|---|---|
| Sandbox base URL | `https://<sandbox host>/api/v1/partner` |
| Token | `lbp_sbx_…` |
| Granted abilities | the exact list above |
| Company | the sandbox company name and id |
| Documentation URL | `https://YOUR-ACTUAL-DOMAIN/api-docs/` — the host from `APP_URL` (see DEPLOYMENT.md §4a) |
| Documentation login | the `shiftmove-docs` Basic Auth user, **in its own message** |
| Reference bundle | `leasyback-partner-api-v1.zip` (see DEPLOYMENT.md §4) — optional once the URL exists; still useful for offline review |

Create the documentation login before this message goes out:

```bash
sudo htpasswd /etc/nginx/.partner-docs-users shiftmove-docs
```

No `-c`. That flag creates and **truncates** the file, deleting every other partner's login.
Full setup, first time only, is DEPLOYMENT.md §4a.3.

---

## Stage 2 — Credential verification (Shiftmove, watched by us)

They run, in order:

1. `GET /health` → `200`, `data.environment` is `sandbox`.
2. `GET /me` → the company and ability set must match what we told them. **If it does not,
   stop.** Everything downstream will be wrong in a way that is hard to diagnose later.

Our check: `partner_api_tokens.last_used_at` is populated for the token we issued, and no
`401`s in the access log for their source. A run of `invalid_token` means they are sending a
token we did not issue — usually a copy/paste truncation.

---

## Stage 3 — Webhook path verification (Shiftmove)

3. `POST /webhooks` with their endpoint and event list, carrying an `Idempotency-Key`.
   Response contains the signing secret **once**. They store it.
4. `POST /webhooks/{id}/test` → queues a signed `webhook.test` through the identical delivery
   path a real event uses.
5. `GET /webhooks/{id}/deliveries` → the delivery must read `succeeded`.

**This is the gate.** Do not progress to stage 4 until a test event has verified on their
side. A signature check that has never verified a real request is the most common cause of a
stalled integration, and every hour spent here is an hour not spent debugging it against live
fleet data.

If it reads `failed`, the attempt's `response_excerpt` and `last_error` usually name the
cause without any instrumentation on their side. The three that account for almost all of it:

| Symptom | Cause |
|---|---|
| `"blocked": true`, no status code | Their hostname resolves to a private or loopback address, or the URL failed the guard for another reason. |
| `301`/`302` recorded as a failure | They are redirecting. We never follow redirects — a `3xx` is the cheapest way to turn a validated public URL into an internal one. They must serve the webhook at the final URL. |
| Signature mismatch on their side | They are re-serialising the parsed JSON. The HMAC covers the **raw** body; in Express that means `express.raw()`, not `express.json()`. |

---

## Stage 4 — Business flow in sandbox (both)

Shiftmove exercises, against fictional data only:

1. `POST /vehicles` — with their own `external_vehicle_id`. The mapping is permanent.
2. `POST /vehicles/{vehicle}/orders` — the return order. Both creates require an
   `Idempotency-Key`; they should retry one with the same key and confirm the same record
   comes back rather than a second one.
3. `GET /orders/{order}/status` and `/timeline` while we move the order through its stages.
4. `GET /orders/{order}/documents` and `/documents/{id}/download` — the two-step signed link.
5. `GET /orders/{order}/offers` — a presented offer.

We move the sandbox order through the workflow from the portal so each transition produces a
real event. Confirm with them that they received:

- `order.created`;
- `order.status_changed` for every transition, **plus** the four narrower events
  (`order.appraisal_completed`, `order.repair_started`, `order.final_appraisal_completed`,
  `order.completed`) alongside it — an integration that treats the pair as a duplicate will
  double-count;
- `document.available` for a published document, and that the download works;
- an offer event.

Also confirm on their side: the handler is idempotent (replay a delivery, nothing doubles),
and a `429` makes them back off rather than retry immediately.

---

## Stage 5 — Production (LeasyBack)

A separate credential for a separate database. Nothing carries over — not the token, not the
subscription, not the data, not the secret.

```bash
php artisan partner:provision shiftmove \
    --company={production_b2b_id} \
    --environment=production \
    --abilities={agreed production set} \
    --force
```

`--force` is required and is the point at which somebody confirms the company id is the
production one.

Then, in order:

1. Confirm the production ability set with them first. It is normal for it to be narrower
   than sandbox.
2. Send the production token and base URL, same channel, same separation as stage 1.
3. They re-run stage 2 against production. `data.environment` must read `production` and the
   company must be the right one.
4. They create the production subscription and store **its** secret — a different secret,
   configured per environment.
5. They send a test event and confirm a `succeeded` delivery.
6. Go live with **one** real vehicle before the rest, and watch that order end to end.

---

## After go-live

| Situation | What we do |
|---|---|
| Their endpoint dies | At 20 consecutive failed deliveries the subscription auto-suspends with a reason they can read over the API. They fix it and `PATCH is_active: true` — which also clears the counter. No action needed from us. |
| They need a new token | `partner:token:rotate shiftmove --environment=production --grace-minutes=60`. The old token keeps working for the window; they deploy rather than cut over. |
| They need to be switched off | `partner:deactivate` (reversible — the same token works again) rather than `partner:token:revoke` (irreversible) unless the credential itself is compromised. |
| Compromised token | `partner:token:revoke`, then `partner:token:rotate`. Revocation is immediate. |
| Compromised webhook secret | They call `POST /webhooks/{id}/rotate-secret` themselves. We do not hold a usable copy. |
| They report a failing request | Ask for the `request_id`. For a webhook, the event id (`evt_…`) and the delivery id. |

---

## What Shiftmove gets, and what they never get

Three credentials, three purposes, three channels. They are unrelated to each other and are
rotated independently — the table in DEPLOYMENT.md §4a.7 is the reference; this is the part that
matters during onboarding.

| Credential | What it opens | Who it is for |
|---|---|---|
| `shiftmove-docs` + password | `{APP_URL}/api-docs/` — the reference, in a browser | their engineers, as people |
| `lbp_…` Bearer token | `{PARTNER_API_DOCS_BASE_URL}/api/v1/partner` — the API | their software |
| Webhook signing secret | verifying the webhooks we send them | their software, at their end |

The signing secret is theirs alone: it is returned once by `POST /webhooks`, we keep no usable
copy, and they rotate it themselves via `POST /webhooks/{id}/rotate-secret`. When they lose it we
cannot read it back to them.

**Shiftmove never receives a LeasyBack Admin account.** Not to read the documentation, not for
support, not for a day. `/partner-api/docs` — the internal preview — sits behind the same gate as
the whole `/admin` section, so an account that opens it opens LeasyBack's staff surface: every
company, every order, every document. `/api-docs` exists precisely so that giving a partner the
documentation never means giving them that. If somebody asks for "just a login so they can see
the docs", the answer is an `htpasswd` line, and it takes ten seconds.

The same separation runs the other way. Removing their docs login does not interrupt the
integration; revoking their token does not close the documentation. Neither is a substitute for
the other when access has to be withdrawn — decide which one you mean.

## What we do not do

- **We do not accept inbound webhooks.** There is no endpoint. Anything they want to tell us
  goes through the documented write endpoints.
- **We do not issue a partner an Admin account**, or any `users` row on the portal, for
  documentation access. See above.
- **We do not add an offer accept/reject endpoint** (§12.14.4 decision 8) or a status-write
  endpoint (§12.12.4 decision 1) because a partner asks. Both are deliberate.
- **We do not send a token by email in the same message as anything else**, and we never
  re-send one — a lost token is rotated.
