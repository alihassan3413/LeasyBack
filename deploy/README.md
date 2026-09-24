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

```bash
ssh deploy@172.105.74.98
bash /var/www/LeasyBack/deploy/deploy.sh
```

Or in one line from your machine:

```bash
ssh deploy@172.105.74.98 'bash /var/www/LeasyBack/deploy/deploy.sh --yes'
```

What it does: maintenance mode → `git reset --hard origin/main` → `composer install --no-dev`
→ `npm ci && npm run build` → back up the SQLite file → `migrate --force` → rebuild caches →
fix permissions → reload php-fpm, restart queue workers and Reverb → maintenance off →
hit `/up`. The app is brought out of maintenance mode even if a step fails.

Flags: `--seed`, `--no-build`, `--no-migrate`, `--branch staging`, `--yes`, `--rollback`.

```bash
bash deploy/deploy.sh --rollback --yes   # back to the previously deployed commit
```

## Database (SQLite)

- Lives at `/var/www/LeasyBack/database/database.sqlite`, owner `deploy`, group `www-data`,
  mode `0664`. The `database/` directory is `2775` because SQLite writes `-wal`/`-shm`
  siblings next to the file.
- Ignored by git (`database/.gitignore` → `*.sqlite*`), so `git reset --hard` during a
  deploy never touches it. `deploy.sh` creates it only when missing and never overwrites it.
- Nginx serves `public/` only, so the database file is not reachable over HTTP.
- `journal_mode=WAL` is set by `provision.sh` and persists in the file header — sessions,
  cache and the queue all share this one file, so readers must not block on writers.
- Every deploy that migrates writes a snapshot to
  `storage/app/backups/database-<timestamp>.sqlite` and keeps the last 10. `--rollback`
  reverts *code only* — restore a snapshot by hand if a migration needs undoing:
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
  `deploy.sh` restarts them so they pick up new code.
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
