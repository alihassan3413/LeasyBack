# Deploying Leasyback to the Linode server

- **Domain:** `leasyback.insuretechgurus.com`
- **Server:** `172.105.74.98`
- **App path:** `/var/www/LeasyBack` (case-sensitive — not `leasyback`)
- **Stack:** Ubuntu + Nginx + PHP-FPM 8.4 + **SQLite** + Redis + Supervisor + Certbot
- **Node:** 22 (assets are built on the server)

| File | What it is |
| --- | --- |
| `config.example.sh` | Server settings. Copy to `config.sh` on the server and edit. Git-ignored. |
| `provision.sh` | One-time server setup. Run **once as root** on a fresh Linode. |
| `deploy.sh` | Every release. Run **as the deploy user**. Safe to re-run. |
| `env.production.example` | Production `.env` template, copied to the app on first provision. |
| `nginx/`, `supervisor/` | Config templates installed by `provision.sh`. |

## 1. First time (once per server)

Point the DNS **A record** for `leasyback.insuretechgurus.com` at `172.105.74.98`, then:

```bash
ssh root@172.105.74.98
apt update && apt install -y git
git clone https://github.com/alihassan3413/LeasyBack.git /var/www/LeasyBack
cd /var/www/LeasyBack/deploy
cp config.example.sh config.sh
nano config.sh          # DOMAIN is already set; check the rest
bash provision.sh
```

`provision.sh` installs PHP 8.4 (incl. `php8.4-sqlite3` and `php8.4-gmp` for web push),
Composer, Node 22, Nginx, Redis, Supervisor, Certbot and `poppler-utils` (required — see
**Gutachten extraction** below); creates the `deploy` user; creates
`database/database.sqlite` **only if it is missing** and makes it writable by `deploy` and
`www-data`; writes the nginx site, the queue-worker and Reverb supervisor programs, the
`schedule:run` cron and the firewall rules. It is idempotent — re-run it any time you
change `config.sh`.

Then finish the setup:

```bash
nano /var/www/LeasyBack/.env                     # replace every CHANGE_ME
php8.4 /var/www/LeasyBack/artisan reverb:install # fresh Reverb credentials
php8.4 /var/www/LeasyBack/artisan webpush:vapid  # VAPID keys for push notifications

certbot --nginx -d leasyback.insuretechgurus.com --redirect --agree-tos -m you@insuretechgurus.com

sudo -u deploy bash /var/www/LeasyBack/deploy/deploy.sh --seed
curl -I https://leasyback.insuretechgurus.com/up
```

`--seed` creates the bootstrap Admin from `ADMIN_SEED_EMAIL` / `ADMIN_SEED_PASSWORD` in
`.env` — set them first, seeding aborts while `ADMIN_SEED_PASSWORD` is empty. Seeding is
idempotent: rerunning it updates that one account instead of creating duplicates, and it
resets the password back to whatever `.env` currently holds.

## 2. Every release after that

One command, nothing to answer:

```bash
# redeploy the branch that is live (e.g. feat/base44-migration on AWS)
bash /var/www/LeasyBack/deploy/deploy.sh

# switch the server to another branch
bash /var/www/LeasyBack/deploy/deploy.sh --branch=main

# only check that everything runs — deploys nothing
bash /var/www/LeasyBack/deploy/deploy.sh --check
```

Every deploy ends with a **system check** that prints ✓ or ✗ — with the exact fix
command under every ✗ — for: the app (`/up`), PHP-FPM, Nginx, the queue (a
`QUEUE_CONNECTION=null` throws the Gutachten extraction away; `sync` runs it inline;
`database`/`redis` need the worker running), Reverb, the scheduler cron, `pdftotext`
and `pdfimages` (Gutachten positions and images), the PHP extensions PHP-FPM loads
(gd, zip, bcmath, intl, sqlite, xml, curl, gmp, …), GD with WebP (thumbnails), the
PHP and Nginx upload limits (a Gutachten may be 50 MB), write access for `www-data`,
`public/storage`, disk space, the mailer and Lexware. A ✗ makes the command exit 1,
but the deploy itself has already gone through.

What still stops a deploy — only what would damage data or leave the app unusable:
no `APP_KEY`, a missing or corrupt SQLite database, a failed backup before migrating,
a failed migration, a branch that does not exist. Everything else is a warning: local
edits to tracked files are **stashed** (`git stash list`), a force-pushed branch is
deployed anyway (`--rollback` returns to the previous commit).

Each run, in order — any failure stops it, and the app is brought back up:

1. **Pre-flight, nothing changed yet:** repo root and `origin` (`REPO_URL`), tools,
   the *running* PHP-FPM version (detected — `PHP_VERSION` only pins it), Node 20+,
   `git fetch` of the branch (a force-push is deployed with a warning), local edits
   stashed, `APP_KEY` present, and the SQLite file: must exist (never created), not a
   symlink, `PRAGMA integrity_check` ok.
2. Maintenance mode → checkout → `composer install --no-dev --optimize-autoloader`
   → `npm ci && npm run build` (fails without `public/build/manifest.json`).
3. SQLite `.backup` to `storage/app/backups/` (mode 600, last `KEEP_BACKUPS`
   kept), the backup's `integrity_check` must pass → `migrate --force` →
   integrity re-checked, no migration may remain pending.
4. `storage:link` if missing, `optimize:clear`, config/view/event caches, route cache where possible.
5. Group `WEB_GROUP` (www-data) on storage, bootstrap/cache and the database, verified.
6. `nginx -t` (when sudo allows it), reload php-fpm and nginx; restart workers/Reverb where configured.
7. Maintenance off → summary (branch/commit, migrations, frontend build) → the system
   check described above.

Output is timestamped (UTC) and written to `DEPLOY_LOG_DIR` (default
`~/leasyback-deploy-logs`, outside the repo). The script runs from a temporary
copy of itself, so the checkout cannot rewrite it mid-run.

A deploy **never** runs `legacy:import` or any `legacy:*` command, `db:seed`
(unless `--seed`), `migrate:fresh`/`db:wipe`, and never touches
`/secure/base44-export`. The Base44 migration stays a separate, explicit action
(`scripts/base44-rehearsal.sh`, then `php artisan legacy:import`).

Flags: `--branch NAME` / `--branch=NAME`, `--check`, `--no-build`, `--no-migrate`,
`--rollback`, `--seed`, `--help`. (`--yes`, `--allow-dirty`, `--allow-non-fast-forward`,
`--rehearsal`, `--production` from earlier versions are accepted and ignored.)

```bash
bash deploy/deploy.sh --rollback   # back to the previously deployed commit
```

**Changed from earlier versions:** there are no modes any more — `--rehearsal`,
`--production` and `--allow-non-production-branch` are accepted for old command lines and
ignored with a note. The mode-specific `.env` rules (rehearsal: everything live switched
off; production: https, mailer, queue, S3, Reverb) are gone; the Base44 safety for an
import stays in `scripts/base44-rehearsal.sh`, which refuses a live `.env` before
`legacy:import` runs. Unchanged: a dirty tree, a failed backup, a failed migration
integrity check and a failed health check stop the deploy; a missing database is never
created; `config.sh` is optional.

## Database (SQLite)

- Lives at `/var/www/LeasyBack/database/database.sqlite`, owner `deploy`, group `www-data`,
  mode `0664`. The `database/` directory is `2775` because SQLite writes `-wal`/`-shm`
  siblings next to the file.
- Ignored by git (`database/.gitignore` → `*.sqlite*`), so a checkout during a deploy
  never touches it. `provision.sh` creates it on a fresh server; `deploy.sh` never
  creates, deletes or overwrites it and stops if it is missing or fails `integrity_check`.
- Nginx serves `public/` only, so the database file is not reachable over HTTP.
- `journal_mode=WAL` is set by `provision.sh` and persists in the file header — sessions,
  cache and the queue all share this one file, so readers must not block on writers.
- Every deploy that migrates writes a verified snapshot to
  `storage/app/backups/database-<timestamp>.sqlite` (`DEPLOY_BACKUP_DIR`, mode 600) and keeps
  the last `KEEP_BACKUPS` (10). `--rollback` reverts *code only* — restore a snapshot by
  hand if a migration needs undoing:
  ```bash
  sudo supervisorctl stop leasyback-worker: leasyback-reverb
  cp storage/app/backups/database-20260803-120000.sqlite database/database.sqlite
  sudo supervisorctl start leasyback-worker: leasyback-reverb
  ```
- If you hit `database is locked` under load, that is SQLite's single-writer limit:
  move `CACHE_STORE`, `QUEUE_CONNECTION` and `SESSION_DRIVER` to `redis` (already
  installed and running) before considering a bigger database.

## Document storage

Private customer documents (leasing contracts, appraisal PDFs, damage photos, invoices) use
their own `documents` disk, **separate from `FILESYSTEM_DISK`**. Application code only ever
calls `Storage::disk('documents')`, so the driver is an env choice, not a code change.

- **`DOCUMENTS_FILESYSTEM_DRIVER=s3` is the recommended production value** and is what
  `env.production.example` ships. It reuses the existing `AWS_*` credentials, so there is no
  extra infrastructure to stand up.
- **Set it explicitly either way.** The config default is `local`; leaving the variable unset
  means customer documents land in `storage/app/private/documents`, which nothing backs up —
  `deploy.sh` snapshots the SQLite file only — and which does not survive a server rebuild.
- **Migrating an existing server from `local` to `s3` needs a copy step first.** Flipping the
  variable makes the app look for every existing document in the bucket; anything still on
  local disk returns 404. Documents are referenced by DB rows holding a relative `path`, and
  those paths are identical on both drivers, so a straight copy is enough:
  ```bash
  cd /var/www/LeasyBack
  php8.4 artisan down
  aws s3 sync storage/app/private/documents "s3://${AWS_BUCKET}/" --acl private
  # flip DOCUMENTS_FILESYSTEM_DRIVER=s3 in .env, then:
  php8.4 artisan config:cache && php8.4 artisan queue:restart && php8.4 artisan up
  ```
  Verify a download from each of the admin, customer and workshop surfaces before deleting
  the local copies — keep them until you have.
- **On `s3`, directory-level `setVisibility()` calls silently no-op** (the disk sets
  `'throw' => false`). Document privacy then rests on the bucket itself, so confirm Block
  Public Access is on and the bucket policy denies anonymous reads. Authorization is
  unaffected — every read still goes through the document's DB record and a Policy first.
- `DOCUMENTS_S3_BUCKET` overrides the bucket for documents only, which is worth using if you
  want versioning or a retention policy that differs from the vehicle-photo bucket.

## Gutachten extraction (`poppler-utils`)

- **`poppler-utils` is a required production dependency**, installed by `provision.sh`. It
  is the application's only OS-level binary dependency: `pdftotext` reads the appraisal PDF
  and `pdfimages` pulls the damage photos out of it.
- Without it nothing looks broken — uploads still succeed, the portal still works — but
  *every* Gutachten upload produces a failed extraction (`no_extractor_available`) and a
  dead `ExtractGutachtenImages` job, so admins get a red extraction card and no damage
  images. `deploy.sh` therefore smoke-checks both binaries on every release and warns if
  either is missing.
- On a server that predates this dependency: `sudo apt-get install -y poppler-utils`, or
  just re-run `provision.sh` (it is idempotent).
- The binary names are configurable via `PDFTOTEXT_BINARY` / `PDFIMAGES_BINARY` in `.env`
  (see `config/gutachten.php`) — only needed if poppler lives outside `PATH`.

## Things worth knowing

- **`.env` is never touched by `deploy.sh`.** Edit it on the server; run
  `php artisan config:cache` (or just redeploy) afterwards.
- **Reverb** runs under supervisor on `127.0.0.1:8080` and port 8080 is **not** open in the
  firewall. Nginx proxies `/app/` and `/apps/` to it over the same TLS domain, which is why
  `.env` has `REVERB_PORT=443` / `REVERB_SCHEME=https` (what the browser connects to) and
  `REVERB_SERVER_HOST=127.0.0.1` / `REVERB_SERVER_PORT=8080` (what the process binds to).
  Set `RUN_REVERB=false` in `config.sh` if you don't need websockets yet.
- **Queue workers** are `queue:work` under supervisor (`QUEUE_WORKERS` in `config.sh`).
  `deploy.sh` restarts them (when they are configured) so they pick up new code. A host
  that must not run them — the AWS copy with migrated data — simply should not have
  `/etc/supervisor/conf.d/leasyback-*.conf` (or the deploy user's `schedule:run` crontab
  line); the deploy then skips them.
- **Route caching is skipped** — `routes/web.php` and `routes/settings.php` register
  closure routes, which Laravel can't serialize. Convert those two to controller
  actions and `deploy.sh` will start caching routes automatically.
- **Frontend build happens on the server.** A 1 GB Linode can OOM during `npm run build`;
  either add swap or build in CI and deploy with `--no-build`.
- **No secrets in git.** `config.sh` and `.env` are git-ignored; the committed templates
  only contain `CHANGE_ME` placeholders.

## Useful commands on the server

```bash
sudo supervisorctl status                      # workers + reverb
tail -f /var/www/LeasyBack/storage/logs/laravel.log
tail -f /var/log/nginx/leasyback-error.log
php8.4 artisan queue:failed                    # failed jobs
which pdftotext && which pdfimages             # Gutachten extraction dependencies
sqlite3 /var/www/LeasyBack/database/database.sqlite '.tables'
sudo systemctl reload php8.4-fpm nginx
```
