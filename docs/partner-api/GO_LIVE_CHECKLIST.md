# Partner API — go-live checklist

Internal. Work top to bottom on the production server. Everything here is verifiable with a
command; nothing on this list is a judgement call.

Related: [DEPLOYMENT.md](DEPLOYMENT.md) for the why, [SHIFTMOVE_ONBOARDING.md](SHIFTMOVE_ONBOARDING.md)
for the partner-facing sequence.

---

## A. Application

- [ ] `php artisan migrate:status | grep partner` — every partner migration reads `Ran`.
- [ ] `php artisan route:list --path=api/v1/partner` — **28 routes**.
- [ ] `php artisan route:cache` succeeds.
- [ ] `php artisan config:cache` run after the last `.env` edit.
- [ ] `php -d memory_limit=1024M vendor/bin/phpunit --no-progress` — no failure beyond the two
      known §6 baselines.

## B. The two operational requirements

- [ ] Worker consumes the webhook queue:
      `ps aux | grep 'queue:work' | grep -c webhooks` → at least 1.
- [ ] Supervisor program actually carries it (not just the template):
      `grep -- '--queue' /etc/supervisor/conf.d/leasyback-worker.conf` → `webhooks,default`.
- [ ] Scheduler cron installed: `crontab -u deploy -l | grep schedule:run`.
- [ ] `php artisan schedule:list` shows `partner:webhooks:dispatch-pending` (5 min) and
      `partner:webhooks:emit-expired-offers` (daily 07:30).
- [ ] `php artisan queue:failed` — empty.

## C. Environment

- [ ] `APP_ENV=production`.
- [ ] `PARTNER_API_WEBHOOK_QUEUE` matches the worker's `--queue`.
- [ ] `PARTNER_API_WEBHOOK_ALLOW_INSECURE=false` and `PARTNER_API_WEBHOOK_ALLOW_PRIVATE=false`.
      (Both are ignored in production regardless — set them correctly anyway, so a future
      environment change does not inherit a permissive value.)
- [ ] `PARTNER_API_DOCS_BASE_URL` set to the host partners call.
- [ ] `PARTNER_API_RATE_LIMIT_PER_MINUTE` agreed with the partner, or their client row
      overrides it.
- [ ] TLS valid on the API host: `curl -sSI https://<host>/api/v1/partner/health` returns a
      `401` (not a certificate error) — unauthenticated is the correct answer here.

## D. Security

- [ ] `curl https://<host>/api/v1/partner/me` with no token → `401`, envelope carries
      `missing_token` and a `request_id`.
- [ ] With a bad token → `401 invalid_token`. Repeat ~25 times → `429` (the per-IP
      failed-auth budget is live).
- [ ] Documentation is **not** publicly reachable:
      `curl -sS -o /dev/null -w '%{http_code}' https://<host>/partner-api/docs` → `302`
      (redirect to login), never `200`.
- [ ] `curl -sS -o /dev/null -w '%{http_code}' https://<host>/partner-api/openapi.yaml` → `302`.
- [ ] Nothing partner-facing under the web root: `ls public/ | grep -i partner` → empty.
- [ ] The generated bundle contains no credential — asserted by
      `PartnerApiDocumentationTest::test_the_generated_bundle_leaks_no_credential_or_storage_path`,
      which runs in the suite above.
- [ ] Document storage is private: no partner response contains a storage path, and
      `/documents/{id}/content` is reachable only through a signed link.

## E. The partner's credential

- [ ] Provisioned with `--environment=production` against the **production** company id.
- [ ] Ability set agreed in writing, and no broader than agreed.
- [ ] Token delivered over a secure channel, separately from the documentation bundle.
- [ ] Token **not** in any ticket, chat log, or email body that also contains the base URL.
- [ ] `GET /me` run by the partner, and its `company` and `token.abilities` confirmed by them
      against what we sent.

## F. The partner's webhook

- [ ] Subscription created by the partner (not by us — the secret must only ever exist on
      their side and in our encrypted column).
- [ ] `POST /webhooks/{id}/test` sent and a `succeeded` delivery confirmed in
      `GET /webhooks/{id}/deliveries`.
- [ ] Partner has confirmed their handler deduplicates on `X-LeasyBack-Event-ID`.
- [ ] Partner has confirmed they verify the signature over the **raw** body and enforce the
      timestamp tolerance.
- [ ] Partner has confirmed they answer inside 10 seconds and queue the work.

## G. Documentation

- [ ] `php artisan partner:docs` run on a machine where `PARTNER_API_DOCS_BASE_URL` is the
      production host.
- [ ] `https://<host>/partner-api/docs` renders for an admin, with the LeasyBack banner.
- [ ] Bundle zipped and sent: `index.html`, `openapi.yaml`, `collection.json`, `events.json`,
      `css/`, `js/`, `images/`.
- [ ] Partner has imported `collection.json` into Postman and made one successful call.

## H. First live order

- [ ] One real vehicle only.
- [ ] `order.created` received by the partner.
- [ ] The order moved one stage from the portal, and both `order.status_changed` and its
      narrower sibling received.
- [ ] A document published and `document.available` received, and downloaded successfully.
- [ ] `partner_webhook_deliveries` for that order: every row `succeeded`.

## I. After the first week

- [ ] Pending-delivery query returns 0:
      `SELECT COUNT(*) FROM partner_webhook_deliveries WHERE status='pending' AND created_at < NOW() - INTERVAL '15 minutes';`
- [ ] `consecutive_failures` on their subscription is 0.
- [ ] No sustained `429`s for their token — if there are, revisit their budget rather than
      asking them to slow down.
- [ ] `php artisan queue:failed` still empty.

---

## Known gaps, accepted at go-live

These are open and deliberate. They are listed so nobody discovers them as a surprise.

1. **No alarm on a stalled webhook queue.** The query in section I is the check; nothing
   automates it (§12.16.15 risk 1).
2. **No usage or audit log for partner reads.** Webhook *deliveries* are fully logged;
   authenticated reads are not (§12.16.15 risk 6).
3. **`CURLOPT_RESOLVE` pinning is unverified end to end.** The SSRF guard's refusal is
   tested; the pinning behind it is defence in depth and untested against a live rebinding
   host (§12.16.15 risk 3).
4. **The replay window is advisory.** We send the timestamp and document the tolerance;
   whether a partner enforces it is theirs (§12.16.15 risk 4).
5. **`offer.updated` covers two situations** — withdrawn, and superseded by a sibling's
   acceptance. A partner tells them apart by reading `offer.status` (§12.16.15 risk 5).
