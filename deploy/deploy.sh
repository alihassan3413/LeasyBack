#!/usr/bin/env bash
#
# Deploy the Leasyback backend. Run on the server as the deploy user:
#
#   bash /var/www/LeasyBack/deploy/deploy.sh --yes                                   # main
#   bash /var/www/LeasyBack/deploy/deploy.sh --branch=feat/base44-migration --yes   # any branch
#
# Options:
#   --branch NAME | --branch=NAME
#                   branch to deploy; must exist on origin (default: main)
#   --no-build      skip `npm ci && npm run build`
#   --no-migrate    skip database migrations
#   --rollback      redeploy the commit that was live before the last deploy
#   --seed          also run `db:seed --force` (first deploy only)
#   --allow-dirty   discard local modifications to tracked files instead of failing
#   --allow-non-fast-forward
#                   accept a branch whose history was rewritten (force-pushed)
#   --yes           don't ask for confirmation
#
# Never seeds unless asked, never runs legacy:import (the Base44 migration is a
# separate, explicit action: scripts/base44-rehearsal.sh), never creates,
# deletes or overwrites the SQLite database, never touches /secure/base44-export.
# Every step stops the deploy on failure; the app is always brought back up.
# Output is timestamped and also written to DEPLOY_LOG_DIR (outside the repo).

set -Eeuo pipefail

readonly SUPERVISOR_WORKER="leasyback-worker"   # deploy/supervisor/leasyback-worker.conf.template
readonly SUPERVISOR_REVERB="leasyback-reverb"   # deploy/supervisor/leasyback-reverb.conf.template
readonly PROTECTED_EXPORT_DIR="/secure/base44-export"

# ---- state -------------------------------------------------------------------
BRANCH_ARG="" SEED=false ROLLBACK=false ASSUME_YES=false ALLOW_DIRTY=false
ALLOW_NON_FF=false DO_BUILD="" DO_MIGRATE=""
PHP="" PHP_FPM_VERSION="" TARGET_COMMIT="" CURRENT_COMMIT="" LOG_FILE="" LOGGER_PID=""
MAINTENANCE_ON=false SQLITE_FILE="" BACKUP_FILE="" BUILD_RESULT="skipped" MIGRATION_RESULT="skipped"

# ---- output ------------------------------------------------------------------
step() { echo; echo "==> $*"; }
info() { echo "    $*"; }
warn() { echo "    WARNING: $*"; }
fail() { echo; echo "DEPLOY FAILED: $*"; exit 1; }

# =============================================================================
# Pure helpers (unit-tested in tests/Feature/Deploy/DeployScriptTest.php)
# =============================================================================

# env_value FILE KEY -> value of the last KEY= line, quotes/inline comment removed
env_value() {
    local line value=""
    [[ -r $1 ]] || return 0
    while IFS= read -r line || [[ -n $line ]]; do
        line=${line#"${line%%[![:space:]]*}"}
        [[ $line == export[[:space:]]* ]] && line=${line#export} && line=${line#"${line%%[![:space:]]*}"}
        [[ $line == "$2="* ]] || continue
        value=${line#"$2="}
    done <"$1"
    if [[ $value == \"* ]]; then
        value=${value#\"}; value=${value%%\"*}
    elif [[ $value == \'* ]]; then
        value=${value#\'}; value=${value%%\'*}
    else
        value=${value%%[[:space:]]#*}
        value=${value%"${value##*[![:space:]]}"}
    fi
    printf '%s' "$value"
}

# url_host URL -> lower-case host without scheme, credentials, port or path
url_host() {
    local u=${1#*://}
    u=${u%%/*}; u=${u##*@}
    if [[ $u == \[* ]]; then u=${u%%]*}; u=${u#\[}; else u=${u%%:*}; fi
    lower "$u"
}

lower() { printf '%s' "$1" | tr '[:upper:]' '[:lower:]'; }

# is_inside CHILD PARENT -> 0 when CHILD is PARENT or below it (resolved)
is_inside() {
    local c p
    c=$(readlink -f -- "$1" 2>/dev/null || printf '%s' "$1")
    p=$(readlink -f -- "$2" 2>/dev/null || printf '%s' "$2"); p=${p%/}
    [[ -n $p && ( $c == "$p" || $c == "$p"/* ) ]]
}

parse_args() {
    while [[ $# -gt 0 ]]; do
        case $1 in
            # Retired: there is one deploy for every branch and server now. Accepted so old command lines still run.
            --rehearsal|--production|--allow-non-production-branch) echo "note: $1 is no longer needed and is ignored" >&2 ;;
            --branch=*)   BRANCH_ARG=${1#--branch=}; [[ -n $BRANCH_ARG ]] || { echo "--branch needs a name" >&2; return 64; } ;;
            --branch)     [[ $# -ge 2 && -n $2 && $2 != --* ]] || { echo "--branch needs a name" >&2; return 64; }; BRANCH_ARG=$2; shift ;;
            --seed)       SEED=true ;;
            --no-build)   DO_BUILD=false ;;
            --no-migrate) DO_MIGRATE=false ;;
            --rollback)   ROLLBACK=true ;;
            --allow-dirty) ALLOW_DIRTY=true ;;
            --allow-non-fast-forward) ALLOW_NON_FF=true ;;
            --yes|-y)     ASSUME_YES=true ;;
            *) echo "unknown option: $1" >&2; return 64 ;;
        esac
        shift
    done
    if [[ $ROLLBACK == true && -n $BRANCH_ARG ]]; then echo "--rollback redeploys the previous commit; it does not take --branch" >&2; return 64; fi
    return 0
}

# =============================================================================
# Pre-flight checks (no changes to the server)
# =============================================================================

# Variables Laravel reads from the real environment before .env.
readonly SHADOWABLE_VARS=(APP_ENV APP_DEBUG APP_URL DB_CONNECTION DB_DATABASE MAIL_MAILER QUEUE_CONNECTION
    BROADCAST_CONNECTION CACHE_STORE SESSION_DRIVER FILESYSTEM_DISK DOCUMENTS_FILESYSTEM_DRIVER LEXWARE_INTEGRATION_MODE)

chk_no_shadowing() { # ENV_FILE
    local k bad=0
    for k in "${SHADOWABLE_VARS[@]}"; do
        [[ -n ${!k+x} ]] || continue
        if [[ ${!k} != "$(env_value "$1" "$k")" ]]; then
            echo "      shell variable $k is set and differs from .env (the shell value would win)"; bad=1
        fi
    done
    return $bad
}

# Mistakes no server should be deployed with, whatever branch or host: no
# APP_KEY, unfilled CHANGE_ME placeholders, or debug output in production.
chk_env_basics() { # ENV_FILE
    local f=$1 bad=0 v placeholders
    v=$(env_value "$f" APP_KEY); [[ $v == base64:?* ]] || { echo "      APP_KEY is not set (php artisan key:generate)"; bad=1; }
    placeholders=$(grep -E '^[[:space:]]*(export[[:space:]]+)?[A-Za-z0-9_]+=["'"'"']?CHANGE_ME' "$f" | sed -E 's/^[[:space:]]*(export[[:space:]]+)?([A-Za-z0-9_]+)=.*/\2/' | sort -u | tr '\n' ' ' || true)
    [[ -z $placeholders ]] || { echo "      still CHANGE_ME: $placeholders"; bad=1; }
    if [[ $(env_value "$f" APP_ENV) == production ]]; then
        v=$(env_value "$f" APP_DEBUG)
        [[ $(lower "$v") != true && $v != 1 ]] || { echo "      APP_DEBUG is on with APP_ENV=production"; bad=1; }
    fi
    return $bad
}

# The PHP-FPM version actually running, rather than a hardcoded one.
detect_php_fpm() {
    local active socket
    active=$(systemctl list-units --type=service --state=active --no-legend --plain 'php*-fpm.service' 2>/dev/null \
        | awk '{print $1}' | sed -nE 's/^php([0-9]+\.[0-9]+)-fpm\.service$/\1/p' | sort -u)
    if [[ -n ${PHP_VERSION:-} ]]; then
        grep -qx -- "$PHP_VERSION" <<<"$active" && { printf '%s' "$PHP_VERSION"; return 0; }
        echo "PHP_VERSION=$PHP_VERSION (config.sh) but php${PHP_VERSION}-fpm is not running; active: ${active:-none}" >&2; return 1
    fi
    case $(grep -c . <<<"$active") in
        1) printf '%s' "$active"; return 0 ;;
        0) echo "no php*-fpm service is running" >&2; return 1 ;;
    esac
    # several versions run: take the one nginx actually hands requests to
    socket=$(grep -rhoE 'php[0-9]+\.[0-9]+-fpm\.sock' /etc/nginx/sites-enabled /etc/nginx/conf.d 2>/dev/null | sed -E 's/^php([0-9.]+)-fpm\.sock$/\1/' | sort -u)
    if [[ $(grep -c . <<<"$socket") == 1 ]] && grep -qx -- "$socket" <<<"$active"; then printf '%s' "$socket"; return 0; fi
    echo "several PHP-FPM versions are running ($(tr '\n' ' ' <<<"$active")); set PHP_VERSION in deploy/config.sh" >&2
    return 1
}

chk_clean_tree() { # REPO
    local changes
    changes=$(git -C "$1" status --porcelain --untracked-files=no)
    [[ -z $changes ]] && return 0
    echo "      local modifications to tracked files:"
    printf '%s\n' "$changes" | sed 's/^/        /'
    return 1
}

# supervisor program lines `name state ...`, empty when supervisor or the program is absent
supervisor_status() { # PROGRAM...
    command -v supervisorctl >/dev/null 2>&1 || return 0
    sudo -n supervisorctl status "$@" 2>/dev/null | grep -vE 'no such (process|group)|ERROR' || true
}

# =============================================================================
# SQLite: never created, deleted or overwritten here
# =============================================================================
sqlite_ok() { # FILE -> 0 when integrity_check says ok
    [[ $(sqlite3 -readonly "$1" 'PRAGMA integrity_check;' 2>&1 | head -n1) == ok ]]
}

backup_sqlite() { # SOURCE DIR -> prints the verified backup path
    local src=$1 dir=$2 target
    mkdir -p -m 700 "$dir"
    target="$dir/database-$(date -u +%Y%m%d-%H%M%S).sqlite"
    [[ ! -e $target ]] || { echo "backup $target already exists" >&2; return 1; }
    sqlite3 "$src" ".backup '$target'" || { echo "sqlite .backup failed" >&2; rm -f -- "$target"; return 1; }
    chmod 600 "$target"
    sqlite_ok "$target" || { echo "the backup $target fails integrity_check" >&2; return 1; }
    printf '%s' "$target"
}

# =============================================================================
# Deploy
# =============================================================================
load_config() {
    local script_dir=$1
    if [[ -f "$script_dir/config.sh" ]]; then
        # shellcheck source=config.example.sh
        source "$script_dir/config.sh"
    fi
    APP_DIR=${APP_DIR:-$(cd "$script_dir/.." && pwd -P)}
    BRANCH=${BRANCH_ARG:-main}
    DO_BUILD=${DO_BUILD:-${BUILD_ASSETS:-true}}
    DO_MIGRATE=${DO_MIGRATE:-${RUN_MIGRATIONS:-true}}
    WEB_GROUP=${WEB_GROUP:-www-data}
    DEPLOY_LOG_DIR=${DEPLOY_LOG_DIR:-$HOME/leasyback-deploy-logs}
    DEPLOY_BACKUP_DIR=${DEPLOY_BACKUP_DIR:-$APP_DIR/storage/app/backups}
    KEEP_BACKUPS=${KEEP_BACKUPS:-10}
}

start_log() {
    local d=$DEPLOY_LOG_DIR
    [[ $d == /* ]] || fail "DEPLOY_LOG_DIR must be absolute"
    if is_inside "$d" "$APP_DIR" || is_inside "$d" "$PROTECTED_EXPORT_DIR"; then fail "DEPLOY_LOG_DIR $d must be outside $APP_DIR and $PROTECTED_EXPORT_DIR"; fi
    mkdir -p -m 700 "$d" || fail "cannot create $d"
    LOG_FILE="$d/deploy-$(date -u +%Y%m%d-%H%M%S).log"
    : >"$LOG_FILE"; chmod 600 "$LOG_FILE"
    exec 3>&1 4>&2
    exec > >(export TZ=UTC; while IFS= read -r line; do printf '%(%Y-%m-%dT%H:%M:%SZ)T %s\n' -1 "$line"; done | tee -a "$LOG_FILE" >&3) 2>&1
    LOGGER_PID=$!
}

stop_log() {
    [[ -n $LOGGER_PID ]] || return 0
    exec 1>&3 2>&4
    wait "$LOGGER_PID" 2>/dev/null || true
    LOGGER_PID=""
}

on_exit() {
    local rc=$?
    if [[ $MAINTENANCE_ON == true ]]; then
        "$PHP" artisan up >/dev/null 2>&1 && echo "    application is back up" || echo "    WARNING: 'artisan up' failed; run it by hand"
    fi
    if [[ $rc != 0 ]]; then
        echo "deploy stopped (exit $rc). Nothing after the failed step ran."
        [[ -n $BACKUP_FILE ]] && echo "database backup taken before migrating: $BACKUP_FILE"
        [[ -n $LOG_FILE ]] && echo "log: $LOG_FILE"
    fi
    stop_log
}

on_err() { echo "    a command failed at line $1 (step aborted)"; }

preflight() {
    step "Pre-flight: deploy of '$BRANCH' into $APP_DIR"

    [[ -d $APP_DIR/.git ]] || fail "$APP_DIR is not a git checkout"
    [[ $(git -C "$APP_DIR" rev-parse --show-toplevel) == "$(readlink -f "$APP_DIR")" ]] || fail "$APP_DIR is not the repository root"
    [[ -f $APP_DIR/.env ]] || fail "$APP_DIR/.env is missing"
    is_inside "$APP_DIR" "$PROTECTED_EXPORT_DIR" && fail "APP_DIR lies inside $PROTECTED_EXPORT_DIR"
    is_inside "$DEPLOY_BACKUP_DIR" "$PROTECTED_EXPORT_DIR" && fail "DEPLOY_BACKUP_DIR lies inside $PROTECTED_EXPORT_DIR"
    [[ $EUID -ne 0 ]] || warn "running as root — files will be owned by root; prefer: sudo -u ${DEPLOY_USER:-deploy} bash $0"

    local remote; remote=$(git -C "$APP_DIR" remote get-url origin 2>/dev/null) || fail "no 'origin' remote"
    if [[ -n ${REPO_URL:-} && ${remote%.git} != "${REPO_URL%.git}" ]]; then fail "origin is $remote, expected $REPO_URL"; fi
    info "repository: $remote"

    local t missing=""
    for t in git composer sqlite3 curl awk sed grep; do command -v "$t" >/dev/null 2>&1 || missing+="$t "; done
    if [[ $DO_BUILD == true ]]; then for t in node npm; do command -v "$t" >/dev/null 2>&1 || missing+="$t "; done; fi
    [[ -z $missing ]] || fail "missing tools: $missing"

    PHP_FPM_VERSION=$(detect_php_fpm) || fail "cannot determine the PHP-FPM version"
    PHP=$(command -v "php${PHP_FPM_VERSION}" || true)
    [[ -n $PHP ]] || fail "php${PHP_FPM_VERSION} CLI not found (it must match php${PHP_FPM_VERSION}-fpm)"
    info "PHP-FPM ${PHP_FPM_VERSION}, CLI $PHP"

    if [[ $DO_BUILD == true ]]; then
        local node_major; node_major=$(node -p 'process.versions.node.split(".")[0]' 2>/dev/null || echo 0)
        (( node_major >= 20 )) || fail "Node $(node -v 2>/dev/null) is too old (need 20+, config says ${NODE_MAJOR:-22})"
        [[ -z ${NODE_MAJOR:-} || $node_major == "$NODE_MAJOR" ]] || warn "Node $node_major differs from NODE_MAJOR=$NODE_MAJOR"
        info "node $(node -v), npm $(npm -v)"
    fi

    cd "$APP_DIR"
    CURRENT_COMMIT=$(git rev-parse HEAD)

    if [[ $ROLLBACK == true ]]; then
        local prev_file="$APP_DIR/storage/app/.last-deployed-commit"
        [[ -f $prev_file ]] || fail "no previous deploy recorded — nothing to roll back to"
        TARGET_COMMIT=$(git rev-parse --verify "$(cat "$prev_file")^{commit}") || fail "recorded commit is unknown"
        BRANCH=$(git rev-parse --abbrev-ref HEAD)
        info "rolling back $BRANCH to ${TARGET_COMMIT:0:8}"
    else
        git check-ref-format --branch "$BRANCH" >/dev/null || fail "'$BRANCH' is not a valid branch name"
        info "fetching $BRANCH"
        git fetch --prune origin "+refs/heads/$BRANCH:refs/remotes/origin/$BRANCH" || fail "branch '$BRANCH' does not exist on origin"
        TARGET_COMMIT=$(git rev-parse "refs/remotes/origin/$BRANCH")
        if [[ $(git rev-parse --abbrev-ref HEAD) == "$BRANCH" ]] && ! git merge-base --is-ancestor "$CURRENT_COMMIT" "$TARGET_COMMIT"; then
            [[ $ALLOW_NON_FF == true ]] || fail "origin/$BRANCH no longer contains the deployed commit ${CURRENT_COMMIT:0:8} (history rewritten); pass --allow-non-fast-forward if intended"
            warn "origin/$BRANCH was rewritten; continuing (--allow-non-fast-forward)"
        fi
    fi
    info "$(git log -1 --pretty='%h %s' "$CURRENT_COMMIT")  ->  $(git log -1 --pretty='%h %s' "$TARGET_COMMIT")"

    if ! chk_clean_tree "$APP_DIR"; then
        [[ $ALLOW_DIRTY == true ]] || fail "the working tree is dirty; commit/stash the changes or pass --allow-dirty to discard them"
        warn "discarding the modifications above (--allow-dirty)"
    fi

    step "Pre-flight: .env checks"
    local env=$APP_DIR/.env
    chk_no_shadowing "$env" || fail "shell variables override .env"
    chk_env_basics "$env" || fail ".env is incomplete"
    info "APP_ENV=$(env_value "$env" APP_ENV)  APP_URL=$(env_value "$env" APP_URL)  mail=$(env_value "$env" MAIL_MAILER)  queue=$(env_value "$env" QUEUE_CONNECTION)"

    step "Pre-flight: SQLite database"
    [[ $(env_value "$env" DB_CONNECTION) == sqlite ]] || fail "DB_CONNECTION is not sqlite; this deploy backs up SQLite only"
    SQLITE_FILE=$(env_value "$env" DB_DATABASE)
    [[ $SQLITE_FILE == /* ]] || SQLITE_FILE="$APP_DIR/$SQLITE_FILE"
    if [[ -n ${SQLITE_PATH:-} && $(readlink -f "$SQLITE_PATH") != "$(readlink -f "$SQLITE_FILE")" ]]; then
        fail "DB_DATABASE ($SQLITE_FILE) differs from SQLITE_PATH in config.sh ($SQLITE_PATH)"
    fi
    [[ -f $SQLITE_FILE ]] || fail "$SQLITE_FILE does not exist — it is never created by a deploy"
    [[ ! -L $SQLITE_FILE ]] || fail "$SQLITE_FILE is a symlink"
    is_inside "$SQLITE_FILE" "$PROTECTED_EXPORT_DIR" && fail "the database lies inside $PROTECTED_EXPORT_DIR"
    sqlite_ok "$SQLITE_FILE" || fail "$SQLITE_FILE fails PRAGMA integrity_check"
    info "$SQLITE_FILE ($(du -h "$SQLITE_FILE" | cut -f1)) integrity ok"
}

deploy() {
    if [[ ${MAINTENANCE_MODE:-true} == true ]]; then
        step "Entering maintenance mode"
        # A release so broken that `artisan down` fails must still be deployable over.
        if "$PHP" artisan down --retry=15 >/dev/null 2>&1; then MAINTENANCE_ON=true
        else warn "artisan down failed on the current release; deploying without maintenance mode"; fi
    fi

    step "Checking out ${TARGET_COMMIT:0:8} ($BRANCH)"
    mkdir -p "$APP_DIR/storage/app"
    echo "$CURRENT_COMMIT" >"$APP_DIR/storage/app/.last-deployed-commit"
    if [[ $ALLOW_DIRTY == true ]]; then
        git checkout --quiet --force -B "$BRANCH" "$TARGET_COMMIT"
    else
        git checkout --quiet -B "$BRANCH" "$TARGET_COMMIT"
    fi
    info "$(git log -1 --pretty='%h %s (%an, %ar)')"

    step "Installing PHP dependencies"
    # Through the detected PHP when composer is a PHP file/phar, so CLI and FPM versions agree.
    local composer; composer=$(command -v composer)
    if head -c 200 "$composer" 2>/dev/null | grep -qaE '^#!.*php|<\?php|__HALT_COMPILER'; then
        "$PHP" "$composer" install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress
    else
        composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress
    fi

    if [[ $DO_BUILD == true ]]; then
        step "Building frontend assets"
        rm -rf public/build   # only this release's hashed assets
        npm ci --include=dev --no-audit --no-fund   # vite/tailwind are devDependencies
        npm run build
        [[ -s public/build/manifest.json ]] || fail "npm run build produced no public/build/manifest.json"
        BUILD_RESULT="built ($(find public/build -type f | wc -l | tr -d ' ') files)"
        info "$BUILD_RESULT"
    else
        info "skipping asset build (--no-build)"
    fi

    if [[ $DO_MIGRATE == true ]]; then
        step "Backing up the database"
        BACKUP_FILE=$(backup_sqlite "$SQLITE_FILE" "$DEPLOY_BACKUP_DIR") || fail "database backup failed — not migrating"
        info "verified backup: $BACKUP_FILE"
        { ls -1t "$DEPLOY_BACKUP_DIR"/database-*.sqlite 2>/dev/null | tail -n +"$((KEEP_BACKUPS + 1))" | xargs -r rm -f --; } || true

        step "Running migrations"
        local pending; pending=$("$PHP" artisan migrate:status --pending --no-ansi 2>/dev/null | grep -c 'Pending' || true)
        info "$pending pending migration(s)"
        "$PHP" artisan migrate --force --no-interaction
        sqlite_ok "$SQLITE_FILE" || fail "$SQLITE_FILE fails integrity_check after migrating — restore $BACKUP_FILE"
        pending=$("$PHP" artisan migrate:status --pending --no-ansi 2>/dev/null | grep -c 'Pending' || true)
        [[ $pending == 0 ]] || fail "$pending migration(s) still pending"
        MIGRATION_RESULT="up to date (backup $BACKUP_FILE)"
    else
        info "skipping migrations (--no-migrate)"
    fi

    if [[ $SEED == true ]]; then
        step "Seeding the database (--seed)"
        "$PHP" artisan db:seed --force --no-interaction
    fi

    step "Rebuilding caches"
    "$PHP" artisan optimize:clear >/dev/null
    if [[ ! -e public/storage ]]; then "$PHP" artisan storage:link >/dev/null && info "public/storage linked"
    elif [[ ! -L public/storage ]]; then warn "public/storage exists but is not a symlink; left as is"; fi
    "$PHP" artisan config:cache
    "$PHP" artisan view:cache
    "$PHP" artisan event:cache
    # closure routes cannot be serialised; cache them as soon as that changes
    if "$PHP" artisan route:cache >/dev/null 2>&1; then info "route cache built"
    else "$PHP" artisan route:clear >/dev/null 2>&1 || true; warn "route:cache skipped — closure routes cannot be cached"; fi

    step "Fixing permissions"
    # `|| true`: files php-fpm created belong to www-data and cannot be changed by
    # the deploy user; the directories that matter are verified right below.
    chgrp -R "$WEB_GROUP" storage bootstrap/cache 2>/dev/null || true
    chmod -R ug+rwX storage bootstrap/cache 2>/dev/null || true
    find storage bootstrap/cache -type d -exec chmod g+s {} + 2>/dev/null || true
    [[ -d public/build ]] && { chgrp -R "$WEB_GROUP" public/build 2>/dev/null || true; }
    chgrp "$WEB_GROUP" "$(dirname "$SQLITE_FILE")" "$SQLITE_FILE"* 2>/dev/null || true
    chmod 0664 "$SQLITE_FILE"* 2>/dev/null || true
    local d
    for d in storage bootstrap/cache "$(dirname "$SQLITE_FILE")"; do
        [[ $(stat -c %G "$d" 2>/dev/null || stat -f %Sg "$d") == "$WEB_GROUP" ]] || fail "$d is not group $WEB_GROUP — add $(id -un) to $WEB_GROUP (usermod -aG $WEB_GROUP $(id -un))"
    done
    info "storage, bootstrap/cache and the database are writable by group $WEB_GROUP"

    step "Reloading PHP-FPM and Nginx"
    sudo -n systemctl reload "php${PHP_FPM_VERSION}-fpm" || fail "cannot reload php${PHP_FPM_VERSION}-fpm (sudoers?)"
    info "php${PHP_FPM_VERSION}-fpm reloaded (opcache cleared)"
    if sudo -n -l nginx >/dev/null 2>&1 || sudo -n -l /usr/sbin/nginx >/dev/null 2>&1; then
        sudo -n nginx -t >/dev/null 2>&1 || fail "nginx -t fails; not reloading"
        info "nginx config valid"
    else
        warn "nginx -t not permitted for $(id -un); a reload keeps the old config if the new one is invalid"
    fi
    sudo -n systemctl reload nginx || fail "cannot reload nginx (sudoers?)"
    info "nginx reloaded"

    restart_background

    if [[ $MAINTENANCE_ON == true ]]; then
        step "Leaving maintenance mode"
        "$PHP" artisan up
        MAINTENANCE_ON=false
    fi
}

# Queue workers and Reverb, where this server runs them: a supervisor program
# that exists is restarted and must come back RUNNING; one that does not exist
# is simply not part of this server. `queue:restart` is always sent — it only
# sets a cache flag, so it is harmless where no worker runs.
restart_background() {
    step "Restarting queue workers and Reverb (where configured)"
    "$PHP" artisan queue:restart >/dev/null && info "queue:restart signalled"
    local program restarted=""
    for program in "$SUPERVISOR_WORKER:" "$SUPERVISOR_REVERB"; do
        [[ -n $(supervisor_status "$program") ]] || { info "${program%:} not configured on this server — skipped"; continue; }
        sudo -n supervisorctl restart "${program/%:/:*}" >/dev/null || fail "cannot restart ${program%:}"
        restarted+="$program "
    done
    [[ -n $restarted ]] || return 0
    sleep 2
    local down; down=$(supervisor_status $restarted | grep -vE 'RUNNING' || true)
    [[ -z $down ]] || fail "not running after restart:$(printf '\n        %s' "$down")"
    info "running: ${restarted//:/}"
}

# GET APP_URL/up through this server's nginx, whatever DNS says.
health_check() {
    local url host port scheme rest code
    url=$(env_value "$APP_DIR/.env" APP_URL); url=${url%/}
    scheme=${url%%://*}; rest=${url#*://}; rest=${rest%%/*}
    host=$(url_host "$url")
    port=${rest##*:}; [[ $port != "$rest" && $port =~ ^[0-9]+$ ]] || port=$([[ $scheme == https ]] && echo 443 || echo 80)
    code=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 20 --resolve "$host:$port:127.0.0.1" "$url/up" 2>/dev/null || true)
    [[ $code == 200 ]] || { echo "      $url/up answered '${code:-no response}'"; return 1; }
}

report() {
    step "Health check"
    health_check || fail "the application is not healthy — see storage/logs/laravel.log and /var/log/nginx/leasyback-error.log"
    info "$(env_value "$APP_DIR/.env" APP_URL)/up answered 200"

    step "Gutachten extraction dependencies"
    local b
    for b in pdftotext pdfimages; do command -v "$b" >/dev/null && info "$b -> $(command -v "$b")" || warn "$b not found (sudo apt-get install -y poppler-utils)"; done

    step "Summary"
    info "deployed:    $BRANCH @ $(git log -1 --pretty='%h %s')"
    info "migrations:  $MIGRATION_RESULT"
    info "frontend:    $BUILD_RESULT"
    info "php-fpm:     php${PHP_FPM_VERSION}-fpm $(systemctl is-active "php${PHP_FPM_VERSION}-fpm" 2>/dev/null || echo unknown)"
    info "nginx:       $(systemctl is-active nginx 2>/dev/null || echo unknown)"
    local s; s=$(supervisor_status "$SUPERVISOR_WORKER:" "$SUPERVISOR_REVERB" | awk '{print $1" "$2}' | tr '\n' ';')
    info "supervisor:  ${s:-no leasyback programs}"
    info "legacy:      legacy:import was NOT run — the Base44 migration stays a separate, explicit step"
    info "log:         $LOG_FILE"
    info "roll back:   bash $APP_DIR/deploy/deploy.sh --rollback --yes"
}

main() {
    local origin_dir
    (( BASH_VERSINFO[0] > 4 || (BASH_VERSINFO[0] == 4 && BASH_VERSINFO[1] >= 2) )) || { echo "deploy.sh needs bash 4.2+ (found $BASH_VERSION)" >&2; exit 64; }
    if [[ -z ${LEASYBACK_DEPLOY_COPY:-} ]]; then
        # Run from a private copy: the checkout below rewrites deploy.sh itself.
        local copy; copy=$(mktemp "${TMPDIR:-/tmp}/leasyback-deploy.XXXXXX")
        cp -- "${BASH_SOURCE[0]}" "$copy"
        LEASYBACK_DEPLOY_COPY=$copy LEASYBACK_DEPLOY_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)" exec bash "$copy" "$@"
    fi
    origin_dir=$LEASYBACK_DEPLOY_DIR
    rm -f -- "$LEASYBACK_DEPLOY_COPY"

    for a in "$@"; do [[ $a == -h || $a == --help ]] && { awk 'NR > 2 && /^#/ { sub(/^# ?/, ""); print; next } NR > 2 { exit }' "$origin_dir/deploy.sh"; exit 0; }; done
    parse_args "$@" || exit 64
    load_config "$origin_dir"

    start_log
    trap on_exit EXIT
    trap 'on_err $LINENO' ERR

    preflight

    [[ -t 0 ]] || ASSUME_YES=true
    if [[ $ASSUME_YES == false ]]; then
        read -rp "Deploy $BRANCH to $(env_value "$APP_DIR/.env" APP_URL) ? [y/N] " reply
        [[ $reply =~ ^[Yy]$ ]] || { echo "Aborted."; exit 0; }
    fi

    deploy
    report
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
    main "$@"
fi
