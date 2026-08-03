#!/usr/bin/env bash
#
# Deploy the Leasyback backend. Run on the server as the deploy user:
#
#   ssh deploy@your-server
#   bash /var/www/LeasyBack/deploy/deploy.sh
#
# Options:
#   --seed          also run `php artisan db:seed --force` (first deploy only)
#   --no-build      skip `npm ci && npm run build`
#   --no-migrate    skip database migrations
#   --branch NAME   deploy a different branch than config.sh's BRANCH
#   --rollback      redeploy the commit that was live before the last deploy
#   --yes           don't ask for confirmation
#
# The app is put into maintenance mode for the duration and is always brought
# back up, even if a step fails.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

for arg in "$@"; do
    if [[ "${arg}" == "-h" || "${arg}" == "--help" ]]; then
        sed -n '3,18p' "${BASH_SOURCE[0]}" | sed 's/^#\{1,\} \{0,1\}//'
        exit 0
    fi
done

if [[ ! -f "${SCRIPT_DIR}/config.sh" ]]; then
    echo "ERROR: ${SCRIPT_DIR}/config.sh not found. Copy config.example.sh to config.sh first."
    exit 1
fi

# shellcheck source=config.example.sh
source "${SCRIPT_DIR}/config.sh"

SEED=false
DO_BUILD="${BUILD_ASSETS:-true}"
DO_MIGRATE="${RUN_MIGRATIONS:-true}"
ROLLBACK=false
ASSUME_YES=false

while [[ $# -gt 0 ]]; do
    case "$1" in
        --seed)       SEED=true ;;
        --no-build)   DO_BUILD=false ;;
        --no-migrate) DO_MIGRATE=false ;;
        --branch)     BRANCH="$2"; shift ;;
        --rollback)   ROLLBACK=true ;;
        --yes|-y)     ASSUME_YES=true ;;
        -h|--help)    ;; # handled before config.sh is sourced
        *)            echo "Unknown option: $1"; exit 1 ;;
    esac
    shift
done

PHP="/usr/bin/php${PHP_VERSION}"
PREVIOUS_COMMIT_FILE="${APP_DIR}/storage/app/.last-deployed-commit"

step() { echo -e "\n\033[1;34m==>\033[0m \033[1m$1\033[0m"; }
info() { echo "    $1"; }
warn() { echo -e "    \033[1;33mWARNING:\033[0m $1"; }
fail() { echo -e "\n\033[1;31mDEPLOY FAILED:\033[0m $1"; exit 1; }

[[ -d "${APP_DIR}" ]] || fail "${APP_DIR} does not exist. Run provision.sh first."
[[ -f "${APP_DIR}/.env" ]] || fail "${APP_DIR}/.env is missing. Run provision.sh first."
command -v "${PHP}" >/dev/null || fail "${PHP} not found. Check PHP_VERSION in config.sh."

if [[ "${EUID}" -eq 0 ]]; then
    warn "running as root — files will be owned by root and php-fpm may fail to write."
    warn "prefer: sudo -u ${DEPLOY_USER} bash ${BASH_SOURCE[0]}"
fi

cd "${APP_DIR}"

# --- Work out what we are deploying ----------------------------------------
CURRENT_COMMIT="$(git rev-parse HEAD)"

if [[ "${ROLLBACK}" == "true" ]]; then
    [[ -f "${PREVIOUS_COMMIT_FILE}" ]] || fail "no previous deploy recorded — nothing to roll back to."
    TARGET_REF="$(cat "${PREVIOUS_COMMIT_FILE}")"
    info "rolling back to ${TARGET_REF}"
else
    step "Fetching ${BRANCH} from origin"
    git fetch --prune origin "${BRANCH}"
    TARGET_REF="origin/${BRANCH}"
fi

TARGET_COMMIT="$(git rev-parse "${TARGET_REF}")"

if [[ "${CURRENT_COMMIT}" == "${TARGET_COMMIT}" && "${ROLLBACK}" == "false" ]]; then
    info "already at $(git log -1 --oneline "${TARGET_COMMIT}") — re-running build and cache steps anyway"
else
    info "$(git log -1 --pretty='%h %s' "${CURRENT_COMMIT}")  ->  $(git log -1 --pretty='%h %s' "${TARGET_COMMIT}")"
fi

if [[ -n "$(git status --porcelain --untracked-files=no)" ]]; then
    warn "there are local modifications in ${APP_DIR} — they will be discarded:"
    git status --short --untracked-files=no | sed 's/^/      /'
fi

# Non-interactive shells (ssh one-liners, CI) can't answer a prompt.
[[ -t 0 ]] || ASSUME_YES=true

if [[ "${ASSUME_YES}" == "false" ]]; then
    read -rp "Deploy to $(grep -E '^APP_URL=' .env | cut -d= -f2-) ? [y/N] " reply
    [[ "${reply}" =~ ^[Yy]$ ]] || { echo "Aborted."; exit 0; }
fi

# --- Always bring the app back up, whatever happens ------------------------
MAINTENANCE_ON=false
bring_app_up() {
    if [[ "${MAINTENANCE_ON}" == "true" ]]; then
        "${PHP}" artisan up >/dev/null 2>&1 && echo -e "\n    application is back up" || true
    fi
}
trap bring_app_up EXIT

if [[ "${MAINTENANCE_MODE:-true}" == "true" ]]; then
    step "Entering maintenance mode"
    "${PHP}" artisan down --retry=15 >/dev/null 2>&1 || true
    MAINTENANCE_ON=true
fi

# --- Code ------------------------------------------------------------------
step "Checking out ${TARGET_COMMIT:0:8}"
echo "${CURRENT_COMMIT}" >"${PREVIOUS_COMMIT_FILE}"
git checkout --quiet --force "${BRANCH}" 2>/dev/null || git checkout --quiet --force -B "${BRANCH}"
git reset --hard --quiet "${TARGET_COMMIT}"
if [[ "${DO_BUILD}" == "true" ]]; then
    # Drop stale hashed assets so public/build only contains this release.
    rm -rf public/build
fi
info "$(git log -1 --pretty='%h %s (%an, %ar)')"

step "Installing PHP dependencies"
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress

if [[ "${DO_BUILD}" == "true" ]]; then
    step "Building frontend assets"
    # devDependencies are required: vite/tailwind live there.
    npm ci --include=dev --no-audit --no-fund
    npm run build
    info "assets built into public/build"
else
    info "skipping asset build (--no-build)"
fi

# --- Database --------------------------------------------------------------
# The SQLite file lives outside git (database/database.sqlite is git-ignored),
# so `git reset --hard` never touches it. Only create it if it is missing —
# never overwrite an existing one, that would wipe production data.
step "Checking the SQLite database"
if [[ ! -f "${SQLITE_PATH}" ]]; then
    warn "${SQLITE_PATH} is missing — creating an empty database"
    install -m 0664 /dev/null "${SQLITE_PATH}"
    sqlite3 "${SQLITE_PATH}" "PRAGMA journal_mode=WAL;" >/dev/null 2>&1 || true
fi
chmod 0664 "${SQLITE_PATH}" 2>/dev/null || true
chgrp www-data "${SQLITE_PATH}" 2>/dev/null || true
chmod 2775 "$(dirname "${SQLITE_PATH}")" 2>/dev/null || true
info "$(du -h "${SQLITE_PATH}" | cut -f1) at ${SQLITE_PATH}"

if [[ "${DO_MIGRATE}" == "true" ]]; then
    # Snapshot before migrating so --rollback has a matching database to
    # restore by hand if a migration turns out to be destructive.
    BACKUP_DIR="${APP_DIR}/storage/app/backups"
    mkdir -p "${BACKUP_DIR}"
    BACKUP_FILE="${BACKUP_DIR}/database-$(date +%Y%m%d-%H%M%S).sqlite"
    if sqlite3 "${SQLITE_PATH}" ".backup '${BACKUP_FILE}'" 2>/dev/null; then
        info "backup written to ${BACKUP_FILE}"
        # Keep the 10 most recent snapshots. `|| true` because pipefail would
        # otherwise abort the deploy when the glob matches nothing.
        { ls -1t "${BACKUP_DIR}"/database-*.sqlite 2>/dev/null | tail -n +11 | xargs -r rm -f; } || true
    else
        warn "could not back up the database — continuing anyway"
    fi

    step "Running migrations"
    "${PHP}" artisan migrate --force --no-interaction
else
    info "skipping migrations (--no-migrate)"
fi

if [[ "${SEED}" == "true" ]]; then
    step "Seeding the database"
    "${PHP}" artisan db:seed --force --no-interaction
fi

# --- Caches ----------------------------------------------------------------
step "Rebuilding caches"
"${PHP}" artisan optimize:clear >/dev/null
"${PHP}" artisan storage:link >/dev/null 2>&1 || true
"${PHP}" artisan config:cache
"${PHP}" artisan view:cache
"${PHP}" artisan event:cache

# routes/web.php and routes/settings.php still register closure routes, which
# Laravel cannot serialize. Try anyway so it starts working the moment those
# become controller actions, and fall back cleanly if it can't.
if "${PHP}" artisan route:cache >/dev/null 2>&1; then
    info "route cache built"
else
    "${PHP}" artisan route:clear >/dev/null 2>&1 || true
    warn "route:cache skipped — closure routes cannot be cached (routes/web.php, routes/settings.php)"
fi

# --- Permissions -----------------------------------------------------------
step "Fixing permissions"
chmod -R ug+rwX storage bootstrap/cache 2>/dev/null || true
chgrp -R www-data storage bootstrap/cache public/build 2>/dev/null || true
# php-fpm may have created -wal/-shm siblings owned by www-data.
chgrp www-data "$(dirname "${SQLITE_PATH}")" "${SQLITE_PATH}"* 2>/dev/null || true
chmod 0664 "${SQLITE_PATH}"* 2>/dev/null || true
info "storage, bootstrap/cache, public/build and the SQLite database writable by www-data"

# --- Services --------------------------------------------------------------
step "Restarting services"
sudo systemctl reload "php${PHP_VERSION}-fpm" && info "php-fpm reloaded (opcache cleared)"
"${PHP}" artisan queue:restart >/dev/null && info "queue workers signalled to restart"

if [[ "${RUN_REVERB:-false}" == "true" ]]; then
    sudo supervisorctl restart leasyback-reverb >/dev/null && info "reverb restarted"
fi
sudo supervisorctl restart leasyback-worker: >/dev/null 2>&1 || true

# --- Done ------------------------------------------------------------------
if [[ "${MAINTENANCE_ON}" == "true" ]]; then
    step "Leaving maintenance mode"
    "${PHP}" artisan up
    MAINTENANCE_ON=false
fi
trap - EXIT

APP_URL="$(grep -E '^APP_URL=' .env | cut -d= -f2- | tr -d '"')"
step "Health check"
if curl -fsS --max-time 15 "${APP_URL}/up" >/dev/null 2>&1; then
    info "${APP_URL}/up responded OK"
else
    warn "${APP_URL}/up did not respond OK — check storage/logs/laravel.log and /var/log/nginx/leasyback-error.log"
fi

echo -e "\n\033[1;32mDeployed:\033[0m $(git log -1 --pretty='%h %s')"
echo "Roll back with: bash ${SCRIPT_DIR}/deploy.sh --rollback --yes"
