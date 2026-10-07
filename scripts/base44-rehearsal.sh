#!/usr/bin/env bash
# =============================================================================
# Base44 -> LeasyBack V2 rehearsal runner
#
# Runs the whole migration rehearsal on a COPIED environment, with safety checks
# that stop it before anything can reach production. It never reads or writes
# /var/www/LeasyBack, except one read-only, lock-free row-count comparison of the
# production SQLite file (skippable with --skip-prod-compare).
#
#   scripts/base44-rehearsal.sh check
#   scripts/base44-rehearsal.sh dry-run
#   scripts/base44-rehearsal.sh full --confirm
#
# Options (all modes):
#   --log-root PATH             where run folders are written (default
#                               /secure/legacy-rehearsal; must be outside both repos)
#   --skip-prod-compare         do not open the production DB at all
#   --probe-documents           HTTP-probe the real Base44 file URLs (1-byte range
#                               requests, nothing stored). `full` downloads them anyway.
#   --maintenance               put the rehearsal app in maintenance mode while it runs
#   --with-dev                  composer install with dev dependencies
#   --sanitize-copy-queues      copy-only: empty jobs/failed_jobs and cancel pending
#                               partner webhook deliveries in the REHEARSAL db (logged)
#   --allow-destructive-migrations
#                               proceed although `migrate --pretend` shows DROP/DELETE
#
# Exit codes: 0 ok | 2 safety hard-stop | 3 stage failure | 64 usage error
#
# Never runs db:seed. Never needs interaction (`full` needs --confirm).
# =============================================================================
set -Eeuo pipefail
umask 077

readonly SCRIPT_VERSION="1.0"
readonly PROD_DIR_LITERAL="/var/www/LeasyBack"             # never overridable
PROD_DIR="${REHEARSAL_PROD_DIR:-$PROD_DIR_LITERAL}"        # override exists for tests
LOG_ROOT_DEFAULT="/secure/legacy-rehearsal"

readonly EXPORT_FILES=(
  Kunde_export.csv
  LeasyBack_Flottenmanagement-users.csv
  Fahrzeug_export.csv
  Auftrag_export.csv
  Auftragskommentar_export.csv
  AuftragStatushistorie_export.csv
  Dateianhang_export.csv
  FahrzeugLead_export.csv
  PendingEinladung_export.csv
  Benachrichtigung_export.csv
)

# Tables that change on their own while the app boots; compared by count only.
readonly VOLATILE_TABLES='cache|cache_locks|sessions'

# Variables Laravel would read from the real environment before .env.
readonly SHADOWABLE_VARS=(APP_ENV APP_URL DB_CONNECTION DB_DATABASE MAIL_MAILER QUEUE_CONNECTION
  BROADCAST_CONNECTION BROADCAST_DRIVER CACHE_STORE SESSION_DRIVER FILESYSTEM_DISK
  DOCUMENTS_FILESYSTEM_DRIVER LEXWARE_INTEGRATION_MODE LEGACY_IMPORT_SOURCE_PATH)

readonly INTEGRATION_KEY_RE='^(STRIPE|AWS|TUVSUD|LEXWARE|REVERB|PUSHER|ABLY|VAPID|TIM|DEKRA|SENTRY|SLACK|TWILIO|MAILGUN|POSTMARK|RESEND|SES|WEBHOOK|PARTNER)_[A-Z0-9_]*(KEY|SECRET|TOKEN|PASSWORD|DSN|WEBHOOK[A-Z0-9_]*|USER_NAME|USERNAME|BUCKET|ID)$'
readonly INTEGRATION_KEY_EXEMPT_RE='(PUBLIC|PUBLISHABLE)'
readonly SAFE_VALUE_RE='^(test|fake|dummy|local|rehearsal|sk_test_|pk_test_|rk_test_|whsec_test)'

# ---- state ------------------------------------------------------------------
MODE=""
CONFIRM=0 SKIP_PROD_COMPARE=0 PROBE_DOCUMENTS=0 MAINTENANCE=0 WITH_DEV=0
SANITIZE=0 ALLOW_DESTRUCTIVE=0
LOG_ROOT="${REHEARSAL_LOG_ROOT:-$LOG_ROOT_DEFAULT}"
REPO="" ENV_FILE="" RUN_DIR="" LOG="" STAGE="init"
DB_FILE="" PROD_DB="" EXPORT_DIR="" WENT_DOWN=0
BATCHES=()          # import batch ids, in order
FAILED_DOCS=0

# =============================================================================
# Logging
# =============================================================================
ts()   { date -u +%Y-%m-%dT%H:%M:%SZ; }
log()  { printf '%s      %s\n' "$(ts)" "$*"; }
pass() { printf '%s PASS  %s\n' "$(ts)" "$*"; }
warn() { printf '%s WARN  %s\n' "$(ts)" "$*"; }

# A safety failure: say why, then stop everything. Nothing after this runs.
refuse() {
  printf '%s FAIL  %s\n' "$(ts)" "$*" >&2
  printf '%s HARD STOP (safety): nothing further was executed.\n' "$(ts)" >&2
  exit 2
}

# Run one named safety check; the check function prints its own reason.
check() {
  local label=$1; shift
  if "$@"; then pass "$label"; else refuse "$label"; fi
}

# =============================================================================
# Pure helpers (unit-tested)
# =============================================================================
real() {
  local p=$1
  readlink -f -- "$p" 2>/dev/null || realpath -m -- "$p" 2>/dev/null || printf '%s' "$p"
}

# is_inside CHILD PARENT -> 0 when CHILD is PARENT or lies below it (after resolving both)
is_inside() {
  local c p
  c=$(real "$1"); p=$(real "$2")
  p=${p%/}
  [[ -n $p ]] || return 1
  [[ $c == "$p" || $c == "$p"/* ]]
}

# env_get FILE KEY -> the last assignment of KEY, unquoted, inline comment removed
env_get() {
  local file=$1 key=$2 line val
  [[ -r $file ]] || return 0
  line=$(grep -E "^[[:space:]]*(export[[:space:]]+)?${key}=" "$file" | tail -n1 || true)
  [[ -n $line ]] || return 0
  val=${line#*=}
  if [[ $val =~ ^\"(.*)\"[[:space:]]*(#.*)?$ ]]; then
    val=${BASH_REMATCH[1]}
  elif [[ $val =~ ^\'(.*)\'[[:space:]]*(#.*)?$ ]]; then
    val=${BASH_REMATCH[1]}
  else
    val=${val%%[[:space:]]#*}
    val=$(printf '%s' "$val" | sed -E 's/[[:space:]]+$//')
  fi
  printf '%s' "$val"
}

# effective KEY -> what PHP will see: a real environment variable wins over .env
effective() {
  local k=$1
  if [[ -n ${!k+x} ]]; then printf '%s' "${!k}"; else env_get "$ENV_FILE" "$k"; fi
}

url_host() {
  printf '%s' "$1" | tr '[:upper:]' '[:lower:]' | sed -E 's~^[a-z][a-z0-9+.-]*://~~; s~^[^@/]*@~~; s~[/:?#].*$~~'
}

file_mode() { stat -c %a -- "$1" 2>/dev/null || stat -f %Lp -- "$1" 2>/dev/null; }
file_id()   { stat -c '%d:%i' -- "$1" 2>/dev/null || stat -f '%d:%i' -- "$1" 2>/dev/null; }
file_links() { stat -c %h -- "$1" 2>/dev/null || stat -f %l -- "$1" 2>/dev/null; }

# mask_pii: stdin -> stdout, for anything meant to be pasted into chat
mask_pii() {
  sed -E \
    -e 's/([A-Za-z0-9._%+-])[A-Za-z0-9._%+-]*@([A-Za-z0-9])[A-Za-z0-9.-]*(\.[A-Za-z]{2,})/\1***@\2***\3/g' \
    -e 's/\+[0-9][0-9 ()\/.-]{6,}[0-9]/[phone-masked]/g' \
    -e 's/(^|[^0-9])0[0-9]{2,5}[ \/-]?[0-9]{5,}/\1[phone-masked]/g'
}

# integration_secret_findings ENVFILE -> prints "KEY" per enabled integration credential
integration_secret_findings() {
  local file=$1 key val
  [[ -r $file ]] || return 0
  while IFS= read -r key; do
    val=$(env_get "$file" "$key")
    [[ -n $val ]] || continue
    if [[ $val =~ (sk|rk|pk)_live_ ]]; then printf '%s (live key)\n' "$key"; continue; fi
    [[ $key =~ $INTEGRATION_KEY_RE ]] || continue
    [[ $key =~ $INTEGRATION_KEY_EXEMPT_RE ]] && continue
    [[ $val =~ $SAFE_VALUE_RE ]] && continue
    printf '%s\n' "$key"
  done < <(grep -oE '^[[:space:]]*(export[[:space:]]+)?[A-Za-z_][A-Za-z0-9_]*=' "$file" | sed -E 's/^[[:space:]]*(export[[:space:]]+)?//; s/=$//' | sort -u)
}

# symlinks_into ROOT FORBIDDEN -> prints "link -> target" for every symlink under ROOT
# (vendor, node_modules and .git are skipped) that points into FORBIDDEN
symlinks_into() {
  local root=$1 forbidden=$2 link target raw
  [[ -e $root ]] || return 0
  while IFS= read -r -d '' link; do
    raw=$(readlink -- "$link" 2>/dev/null || true)
    target=$(real "$link")
    if is_inside "$target" "$forbidden" || [[ $raw == "${forbidden%/}"* ]]; then
      printf '%s -> %s\n' "$link" "${target:-$raw}"
    fi
  done < <(find "$root" \( -name vendor -o -name node_modules -o -name .git \) -prune -o -type l -print0 2>/dev/null)
}

# cmp_counts A B MODE IGNORE_RE   (MODE: counts | full)
# Files are "table<TAB>rows<TAB>checksum". Prints the differences; returns 1 if any.
cmp_counts() {
  local a=$1 b=$2 mode=$3 ignore=${4:-^$} fa fb rc=0
  fa=$(mktemp); fb=$(mktemp)
  norm_counts "$a" "$mode" "$ignore" >"$fa"
  norm_counts "$b" "$mode" "$ignore" >"$fb"
  diff "$fa" "$fb" || rc=1
  rm -f "$fa" "$fb"
  return $rc
}
norm_counts() {
  local f=$1 mode=$2 ignore=$3
  { grep -Ev -- "^(${ignore})"$'\t' "$f" || true; } | { if [[ $mode == counts ]]; then cut -f1,2; else cat; fi; } | sort
}

parse_batch()  { sed -nE 's/.*Batch ([0-9a-f-]{36}).*/\1/p' | tail -n1; }
parse_report() { sed -nE 's/.*Report: (.+)$/\1/p' | tail -n1; }

# count_action CSV ACTION -> rows of the reconciliation report with that action
count_action() {
  [[ -r $1 ]] || { echo 0; return; }
  grep -cE "^[a-z_]+,\"?[^,\"]*\"?,$2," "$1" || true
}

# =============================================================================
# Static safety checks (no Laravel, no composer, no DB writes)
# =============================================================================
chk_not_production_dir() {
  local here repo p pr
  here=$(pwd -P); repo=$(real "$REPO")
  for p in "$PROD_DIR_LITERAL" "$PROD_DIR"; do
    pr=$(real "$p")
    if is_inside "$repo" "$pr" || is_inside "$here" "$pr"; then
      echo "      the working copy ($repo) is inside production ($pr)"; return 1
    fi
  done
}

chk_env_file() {
  [[ -f $ENV_FILE ]] || { echo "      $ENV_FILE not found"; return 1; }
  local mode; mode=$(file_mode "$ENV_FILE")
  [[ ${mode:-777} -le 640 ]] || { echo "      .env mode is $mode (must be 640 or tighter)"; return 1; }
  local app_env; app_env=$(effective APP_ENV)
  if [[ -e "$REPO/.env.$app_env" ]]; then echo "      .env.$app_env exists and would override .env"; return 1; fi
}

chk_no_shadowing() {
  local k fileval bad=0
  for k in "${SHADOWABLE_VARS[@]}"; do
    [[ -n ${!k+x} ]] || continue
    fileval=$(env_get "$ENV_FILE" "$k")
    if [[ ${!k} != "$fileval" ]]; then
      echo "      shell variable $k is set and differs from .env (the shell value wins)"; bad=1
    fi
  done
  return $bad
}

chk_app_env_local() {
  local v; v=$(effective APP_ENV)
  [[ $v != production ]] || { echo "      APP_ENV=production"; return 1; }
  [[ $v == local ]]      || { echo "      APP_ENV is '$v'; the rehearsal requires local"; return 1; }
}

prod_url() {
  if [[ -n ${REHEARSAL_PROD_URL:-} ]]; then printf '%s' "$REHEARSAL_PROD_URL"; else env_get "$PROD_DIR/.env" APP_URL; fi
}

chk_app_url() {
  local mine prod
  mine=$(url_host "$(effective APP_URL)")
  prod=$(url_host "$(prod_url)")
  [[ -n $prod ]] || { echo "      cannot learn the production URL (read-only look at $PROD_DIR/.env failed); set REHEARSAL_PROD_URL"; return 1; }
  [[ -n $mine ]] || { echo "      APP_URL is empty; set it to the rehearsal address"; return 1; }
  [[ $mine != "$prod" ]] || { echo "      APP_URL host $mine is the production host"; return 1; }
}

chk_mail_log() {
  local v; v=$(effective MAIL_MAILER)
  [[ $v == log ]] || { echo "      MAIL_MAILER is '${v:-<unset>}' (must be log)"; return 1; }
}

chk_queue_and_broadcast() {
  local q b c s bad=0
  q=$(effective QUEUE_CONNECTION); b=$(effective BROADCAST_CONNECTION); [[ -n $b ]] || b=$(effective BROADCAST_DRIVER)
  c=$(effective CACHE_STORE);      s=$(effective SESSION_DRIVER)
  [[ $q == null ]] || { echo "      QUEUE_CONNECTION is '${q:-<unset>}' (must be null: no job may ever run)"; bad=1; }
  [[ $b == log || $b == null ]] || { echo "      BROADCAST_CONNECTION is '${b:-<unset>}' (must be log or null)"; bad=1; }
  case $c in redis|memcached|dynamodb) echo "      CACHE_STORE=$c could share production state"; bad=1;; esac
  case $s in redis|memcached|dynamodb) echo "      SESSION_DRIVER=$s could share production state"; bad=1;; esac
  return $bad
}

chk_storage_not_s3() {
  local f d
  f=$(effective FILESYSTEM_DISK); d=$(effective DOCUMENTS_FILESYSTEM_DRIVER)
  if [[ $f == s3 || $d == s3 ]]; then echo "      an S3 disk is selected (FILESYSTEM_DISK=$f DOCUMENTS_FILESYSTEM_DRIVER=$d)"; return 1; fi
}

chk_integrations() {
  local found lex
  found=$(integration_secret_findings "$ENV_FILE")
  if [[ -n $found ]]; then
    echo "      integration credentials are enabled (blank them or set them to a test/fake value):"
    printf '        %s\n' $found
    return 1
  fi
  lex=$(effective LEXWARE_INTEGRATION_MODE)
  [[ $lex == disabled ]] || { echo "      LEXWARE_INTEGRATION_MODE is '${lex:-<unset>}' (must be disabled)"; return 1; }
}

abs_db_path() { # ENVFILE BASEDIR -> absolute DB path
  local p; p=$(env_get "$1" DB_DATABASE)
  [[ -n $p ]] || return 0
  [[ $p == /* ]] && printf '%s' "$p" || printf '%s/%s' "$2" "$p"
}

chk_database() {
  local conn; conn=$(effective DB_CONNECTION)
  [[ $conn == sqlite ]] || { echo "      DB_CONNECTION is '$conn' (the rehearsal supports sqlite only)"; return 1; }
  DB_FILE=$(effective DB_DATABASE)
  [[ $DB_FILE == /* ]] || { echo "      DB_DATABASE must be an absolute path (got '${DB_FILE:-<unset>}')"; return 1; }
  [[ -f $DB_FILE ]]    || { echo "      $DB_FILE does not exist (copy the database in first)"; return 1; }
  [[ ! -L $DB_FILE ]]  || { echo "      the rehearsal DB is a symlink"; return 1; }
  if is_inside "$DB_FILE" "$PROD_DIR_LITERAL" || is_inside "$DB_FILE" "$PROD_DIR"; then
    echo "      the rehearsal DB lies inside production"; return 1
  fi
  PROD_DB=${REHEARSAL_PROD_DB:-$(abs_db_path "$PROD_DIR/.env" "$PROD_DIR")}
  [[ -n $PROD_DB ]] || { echo "      cannot learn the production DB path (set REHEARSAL_PROD_DB)"; return 1; }
  [[ $(real "$DB_FILE") != "$(real "$PROD_DB")" ]] || { echo "      DB_DATABASE resolves to the production database"; return 1; }
  local f
  for f in "" "-wal" "-shm"; do
    [[ -e "$DB_FILE$f" ]] || continue
    [[ ! -L "$DB_FILE$f" ]] || { echo "      $DB_FILE$f is a symlink"; return 1; }
    [[ "$(file_links "$DB_FILE$f")" == 1 ]] || { echo "      $DB_FILE$f has several hard links"; return 1; }
    if [[ -e "$PROD_DB$f" && "$(file_id "$DB_FILE$f")" == "$(file_id "$PROD_DB$f")" ]]; then
      echo "      $DB_FILE$f is the same file (inode) as production's"; return 1
    fi
  done
}

chk_symlinks() {
  local root hits=""
  for root in "$REPO/storage" "$REPO/public" "$REPO/bootstrap/cache" "$REPO/database" "$REPO"; do
    hits+=$(symlinks_into "$root" "$PROD_DIR_LITERAL")$'\n'
    [[ $PROD_DIR == "$PROD_DIR_LITERAL" ]] || hits+=$(symlinks_into "$root" "$PROD_DIR")$'\n'
  done
  hits=$(printf '%s' "$hits" | sed '/^$/d' | sort -u)
  if [[ -n $hits ]]; then echo "      symlinks into production:"; printf '        %s\n' "$hits"; return 1; fi
  if [[ -L "$REPO/storage" || -L "$REPO/storage/app" ]]; then echo "      storage is a symlink"; return 1; fi
}

chk_no_stale_config_cache() {
  local cache="$REPO/bootstrap/cache/config.php"
  [[ -f $cache ]] || return 0
  if grep -qF -- "$PROD_DIR_LITERAL" "$cache" || { [[ -n $PROD_DB ]] && grep -qF -- "$PROD_DB" "$cache"; }; then
    echo "      bootstrap/cache/config.php references production"; return 1
  fi
  warn "bootstrap/cache/config.php exists; it is cleared before any artisan command runs"
}

chk_export() {
  EXPORT_DIR=$(effective LEGACY_IMPORT_SOURCE_PATH)
  [[ -n $EXPORT_DIR ]]    || { echo "      LEGACY_IMPORT_SOURCE_PATH is not set"; return 1; }
  [[ $EXPORT_DIR == /* ]] || { echo "      LEGACY_IMPORT_SOURCE_PATH must be absolute"; return 1; }
  [[ -d $EXPORT_DIR ]]    || { echo "      $EXPORT_DIR is not a directory"; return 1; }
  EXPORT_DIR=$(real "$EXPORT_DIR")
  local d
  for d in "$REPO" "$PROD_DIR_LITERAL" "$PROD_DIR"; do
    if is_inside "$EXPORT_DIR" "$d"; then echo "      the export lies inside $d (it must live outside both repositories)"; return 1; fi
  done
  local f missing=0
  for f in "${EXPORT_FILES[@]}"; do
    [[ -s "$EXPORT_DIR/$f" ]] || { echo "      missing or empty: $f"; missing=1; }
  done
  [[ $missing == 0 ]] || return 1
  local mode; mode=$(file_mode "$EXPORT_DIR")
  [[ ${mode:-777} -le 750 ]] || warn "export folder mode is $mode; 700 is recommended"
}

chk_log_root() {
  local d
  for d in "$REPO" "$PROD_DIR_LITERAL" "$PROD_DIR"; do
    if is_inside "$LOG_ROOT" "$d"; then echo "      log root $LOG_ROOT is inside $d"; return 1; fi
  done
}

# Anything that could start, schedule or serve the rehearsal copy automatically.
chk_no_automation() {
  local needle1 needle2 bad=0 d hit unreadable=""
  needle1=$(real "$REPO"); needle2=$REPO
  local -a dirs=(/etc/cron.d /etc/cron.hourly /etc/cron.daily /etc/crontab /var/spool/cron /var/spool/cron/crontabs
    /etc/supervisor /etc/supervisord.d /etc/supervisord.conf
    /etc/systemd/system /lib/systemd/system /usr/lib/systemd/system "$HOME/.config/systemd"
    /etc/nginx /etc/apache2 /etc/httpd /etc/caddy /etc/php /etc/php-fpm.d)
  for d in "${dirs[@]}"; do
    [[ -e $d ]] || continue
    if [[ ! -r $d ]]; then unreadable+="$d "; continue; fi
    hit=$(grep -rIsl --fixed-strings -e "$needle1" -e "$needle2" "$d" 2>/dev/null | head -n3 || true)
    if [[ -n $hit ]]; then echo "      configured for the rehearsal directory:"; printf '        %s\n' $hit; bad=1; fi
  done
  hit=$( { crontab -l 2>/dev/null || true; } | grep -F -e "$needle1" -e "$needle2" || true)
  if [[ -n $hit ]]; then echo "      user crontab mentions the rehearsal directory"; bad=1; fi
  hit=$(ps -eo args 2>/dev/null | grep -F -e "$needle1" -e "$needle2" | grep -E 'queue:(work|listen)|schedule:(run|work)|reverb:start|horizon|artisan serve|php-fpm' | grep -v grep || true)
  if [[ -n $hit ]]; then echo "      a worker/scheduler/server process is running for the rehearsal directory"; bad=1; fi
  if [[ -n $unreadable ]]; then
    if [[ ${REHEARSAL_ALLOW_UNREADABLE_SCAN:-0} == 1 ]]; then
      warn "could not inspect: $unreadable (allowed by REHEARSAL_ALLOW_UNREADABLE_SCAN=1)"
    else
      echo "      cannot inspect: $unreadable (run with sudo, or set REHEARSAL_ALLOW_UNREADABLE_SCAN=1 after checking by hand)"; bad=1
    fi
  fi
  return $bad
}

chk_tools() {
  local t missing=0
  for t in php sqlite3 sha256sum git find awk sed grep diff stat; do
    command -v "$t" >/dev/null 2>&1 || { echo "      missing tool: $t"; missing=1; }
  done
  if (( PROBE_DOCUMENTS )) && ! command -v curl >/dev/null 2>&1; then echo "      missing tool: curl"; missing=1; fi
  if [[ $MODE != check ]] && ! command -v composer >/dev/null 2>&1; then echo "      missing tool: composer"; missing=1; fi
  return $missing
}

chk_sqlite_sound() { # FILE LABEL
  local r
  r=$(sqlite3 -readonly "$1" 'PRAGMA integrity_check;' 2>&1 | head -n3)
  [[ $r == ok ]] || { echo "      integrity_check on $2: $r"; return 1; }
}

run_static_checks() {
  STAGE="safety: static checks"
  log "mode=$MODE  repo=$(real "$REPO")  prod=$PROD_DIR_LITERAL (never touched)"
  check "working directory is not the production tree"      chk_not_production_dir
  check "required tools are installed"                      chk_tools
  check ".env present, private, no environment override file" chk_env_file
  check "no shell variable shadows .env"                    chk_no_shadowing
  check "APP_ENV is local (not production)"                 chk_app_env_local
  check "APP_URL is not the production URL"                 chk_app_url
  check "MAIL_MAILER=log"                                   chk_mail_log
  check "queue=null, broadcast log/null, no shared cache"   chk_queue_and_broadcast
  check "no S3 disk selected"                               chk_storage_not_s3
  check "no live Stripe/TUV/S3/Lexware/Reverb/webhook credentials" chk_integrations
  check "rehearsal DB is its own file, never production's"  chk_database
  check "no symlink points into production"                 chk_symlinks
  check "no stale config cache references production"       chk_no_stale_config_cache
  check "log root is outside both repositories"             chk_log_root
  check "export is outside both repositories and complete"  chk_export
  check "no cron/supervisor/systemd/vhost/worker for this directory" chk_no_automation
  check "rehearsal DB passes integrity_check"               chk_sqlite_sound "$DB_FILE" "the rehearsal DB"
}

# =============================================================================
# Run folder, logging, summary
# =============================================================================
init_run_dir() {
  local stamp; stamp=$(date -u +%Y%m%dT%H%M%SZ)
  RUN_DIR="$LOG_ROOT/$stamp"
  mkdir -p -m 700 "$RUN_DIR" "$RUN_DIR/reports" || { echo "cannot create $RUN_DIR" >&2; exit 2; }
  LOG="$RUN_DIR/rehearsal.log"
  : >"$LOG"; chmod 600 "$LOG"
  exec > >(tee -a "$LOG") 2>&1
}

record_environment() {
  {
    echo "base44-rehearsal $SCRIPT_VERSION  mode=$MODE  started=$(ts)"
    echo "host=$(hostname)  user=$(id -un)"
    echo "repo=$(real "$REPO")"
    echo "git=$(git -C "$REPO" rev-parse --abbrev-ref HEAD 2>/dev/null) $(git -C "$REPO" rev-parse --short HEAD 2>/dev/null)"
    echo "php=$(php -r 'echo PHP_VERSION;')  sqlite3=$(sqlite3 --version | cut -d' ' -f1)"
    echo "rehearsal_db=$DB_FILE  size_bytes=$(stat -c %s "$DB_FILE" 2>/dev/null || stat -f %z "$DB_FILE")"
    echo "production_db=(never opened for writing) $PROD_DB"
    echo "app_env=$(effective APP_ENV)  app_url_host=$(url_host "$(effective APP_URL)")"
    echo "mail=$(effective MAIL_MAILER)  queue=$(effective QUEUE_CONNECTION)  broadcast=$(effective BROADCAST_CONNECTION)"
    echo "export_dir=$EXPORT_DIR"
    echo "flags: skip_prod_compare=$SKIP_PROD_COMPARE probe_documents=$PROBE_DOCUMENTS maintenance=$MAINTENANCE with_dev=$WITH_DEV sanitize_copy_queues=$SANITIZE allow_destructive_migrations=$ALLOW_DESTRUCTIVE"
  } >"$RUN_DIR/environment-summary.txt"
}

record_export_hashes() {
  ( cd "$EXPORT_DIR" && sha256sum -- "${EXPORT_FILES[@]}" ) >"$RUN_DIR/export.sha256"
  chmod 600 "$RUN_DIR/export.sha256"
  pass "export checksums recorded ($(wc -l <"$RUN_DIR/export.sha256") files)"
}

verify_export_hashes() {
  STAGE="export checksums (final)"
  if ( cd "$EXPORT_DIR" && sha256sum -c --quiet "$RUN_DIR/export.sha256" ); then
    pass "export files are byte-identical to the start of the run"
  else
    refuse "the export changed during the run"
  fi
}

# summary-masked.txt is the only file meant to be pasted anywhere
write_masked_summary() {
  local out="$RUN_DIR/summary-masked.txt"
  {
    cat "$RUN_DIR/environment-summary.txt"
    echo; echo "--- stages completed: ${COMPLETED:-none}"
    local f
    for f in "$RUN_DIR"/reports/*/*/summary.json; do
      [[ -f $f ]] || continue
      echo "--- $(basename "$(dirname "$(dirname "$f")")") report counts:"; cat "$f"
    done
    for f in "$RUN_DIR"/compare-*.txt "$RUN_DIR"/prod-compare.txt; do
      [[ -f $f ]] || continue
      echo "--- $(basename "$f"):"; cat "$f"
    done
  } | mask_pii >"$out"
  chmod 600 "$out"
}

on_err() {
  local rc=$? line=$1
  printf '%s FAIL  stage "%s" failed (exit %s, line %s)\n' "$(ts)" "$STAGE" "$rc" "$line" >&2
  FAILED_STAGE=$STAGE
}
on_exit() {
  local rc=$?
  trap - ERR EXIT
  if (( WENT_DOWN )) && [[ -x "$REPO/artisan" ]]; then
    ( cd "$REPO" && php artisan up >/dev/null 2>&1 ) && log "rehearsal app is out of maintenance mode again" || true
  fi
  if [[ -n $RUN_DIR && -d $RUN_DIR ]]; then
    write_masked_summary 2>/dev/null || true
    echo
    if (( rc == 0 )); then printf '%s DONE  mode=%s succeeded\n' "$(ts)" "$MODE"
    else printf '%s ABORTED  mode=%s exit=%s stage="%s"\n' "$(ts)" "$MODE" "$rc" "${FAILED_STAGE:-$STAGE}"; fi
    echo "LOG DIRECTORY: $RUN_DIR"
    echo "Paste only: $RUN_DIR/summary-masked.txt  (raw logs hold customer data; keep them private)"
    sleep 0.3
  fi
  exit "$rc"
}

# =============================================================================
# SQLite helpers
# =============================================================================
sql() { sqlite3 -readonly "$DB_FILE" "$1"; }

fk_violations() { sqlite3 -readonly "$DB_FILE" 'PRAGMA foreign_key_check;' | wc -l | tr -d ' '; }

# capture_counts OUTFILE -> "table<TAB>rows<TAB>checksum" for every table
capture_counts() {
  local out=$1 t n sum
  : >"$out"
  while IFS= read -r t; do
    n=$(sqlite3 -readonly "$DB_FILE" "select count(*) from \"$t\";")
    sum=$( { sqlite3 -readonly "$DB_FILE" "select * from \"$t\" order by rowid;" 2>/dev/null \
             || sqlite3 -readonly "$DB_FILE" "select * from \"$t\";"; } | sha256sum | cut -c1-64)
    printf '%s\t%s\t%s\n' "$t" "$n" "$sum" >>"$out"
  done < <(sqlite3 -readonly "$DB_FILE" "select name from sqlite_master where type='table' and name not like 'sqlite_%' order by name;")
  log "captured $(wc -l <"$out") tables -> $(basename "$out")"
}

# read-only, lock-free count of key tables in production (approximate by design)
prod_compare() {
  [[ $SKIP_PROD_COMPARE == 1 ]] && { log "production comparison skipped"; return 0; }
  local out="$RUN_DIR/prod-compare.txt" t a b
  { echo "table  rehearsal_copy  production(read-only, immutable snapshot)"
    for t in users b2b user_b2b vehicles leasyback_orders order_messages vehicle_report_documents; do
      a=$(sql "select count(*) from $t;" 2>/dev/null || echo "-")
      b=$(sqlite3 "file:${PROD_DB}?mode=ro&immutable=1" "select count(*) from $t;" 2>/dev/null || echo "n/a")
      printf '%s  %s  %s\n' "$t" "$a" "$b"
    done; } >"$out"
  log "production comparison (read-only):"; sed 's/^/        /' "$out"
}

doc_root() { php_cfg docs_root; }
doc_file_count() {
  local root; root=$(doc_root)
  [[ -n $root && -d $root ]] && find "$root" -type f | wc -l | tr -d ' ' || echo 0
}

# =============================================================================
# Laravel
# =============================================================================
artisan() { ( cd "$REPO" && php -d memory_limit="${REHEARSAL_PHP_MEMORY:-2G}" artisan "$@" ); }

# run_artisan OUTFILE ARGS... : tee to a file, return artisan's exit code
run_artisan() {
  local out=$1 rc; shift
  log "artisan $*"
  set +e
  artisan "$@" 2>&1 | tee "$out"
  rc=${PIPESTATUS[0]}
  set -e
  return "$rc"
}

CFG_JSON=""
load_effective_config() {
  CFG_JSON=$( cd "$REPO" && php -d memory_limit=512M -r '
    require "vendor/autoload.php";
    $app = require "bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    echo "CFG:", json_encode([
      "app_env" => config("app.env"), "app_url" => config("app.url"),
      "db_default" => config("database.default"), "db_path" => config("database.connections.sqlite.database"),
      "mail" => config("mail.default"), "queue" => config("queue.default"),
      "broadcast" => config("broadcasting.default"), "cache" => config("cache.default"),
      "session" => config("session.driver"), "fs_default" => config("filesystems.default"),
      "docs_driver" => config("filesystems.disks.documents.driver"), "docs_root" => config("filesystems.disks.documents.root"),
      "source_path" => config("legacy_import.source_path"),
    ]);' 2>/dev/null | sed -n 's/^CFG://p' | tail -n1)
  [[ -n $CFG_JSON ]] || { echo "      Laravel could not report its effective configuration" >&2; return 1; }
}
php_cfg() { php -r '$j = json_decode($argv[1], true); echo $j[$argv[2]] ?? "";' -- "$CFG_JSON" "$1"; }

chk_effective_config() {
  local bad=0 v
  v=$(php_cfg app_env);     [[ $v == local ]]  || { echo "      effective app.env is '$v'"; bad=1; }
  v=$(php_cfg app_url);     [[ $(url_host "$v") != "$(url_host "$(prod_url)")" ]] || { echo "      effective app.url is the production URL"; bad=1; }
  v=$(php_cfg db_default);  [[ $v == sqlite ]] || { echo "      effective database.default is '$v'"; bad=1; }
  v=$(php_cfg db_path)
  [[ $(real "$v") == "$(real "$DB_FILE")" ]] || { echo "      Laravel would open '$v', not the rehearsal DB '$DB_FILE'"; bad=1; }
  [[ $(real "$v") != "$(real "$PROD_DB")" ]] || { echo "      Laravel would open the production DB"; bad=1; }
  v=$(php_cfg mail);        [[ $v == log ]]    || { echo "      effective mail.default is '$v'"; bad=1; }
  v=$(php_cfg queue);       [[ $v == null ]]   || { echo "      effective queue.default is '$v'"; bad=1; }
  v=$(php_cfg broadcast);   [[ $v == log || $v == null ]] || { echo "      effective broadcasting.default is '$v'"; bad=1; }
  v=$(php_cfg cache);       [[ $v != redis && $v != memcached && $v != dynamodb ]] || { echo "      effective cache store is '$v'"; bad=1; }
  v=$(php_cfg session);     [[ $v != redis && $v != memcached && $v != dynamodb ]] || { echo "      effective session driver is '$v'"; bad=1; }
  v=$(php_cfg fs_default);  [[ $v != s3 ]]     || { echo "      default filesystem disk is s3"; bad=1; }
  v=$(php_cfg docs_driver); [[ $v == local ]]  || { echo "      documents disk driver is '$v' (must be local)"; bad=1; }
  v=$(php_cfg docs_root)
  [[ -n $v ]] && is_inside "$v" "$REPO" || { echo "      documents disk root '$v' is not inside the rehearsal tree"; bad=1; }
  if is_inside "$v" "$PROD_DIR_LITERAL"; then echo "      documents disk root is inside production"; bad=1; fi
  v=$(php_cfg source_path); [[ $(real "$v") == "$EXPORT_DIR" ]] || { echo "      legacy_import.source_path '$v' differs from the checked export '$EXPORT_DIR'"; bad=1; }
  return $bad
}

# =============================================================================
# Stages
# =============================================================================
COMPLETED=""
done_stage() { COMPLETED+="${COMPLETED:+, }$1"; pass "stage complete: $1"; }

stage_prepare() {
  STAGE="composer install"
  local flags=(--no-interaction --no-progress --optimize-autoloader)
  (( WITH_DEV )) || flags+=(--no-dev)
  log "composer install ${flags[*]}"
  ( cd "$REPO" && composer install "${flags[@]}" ) 2>&1 | tee "$RUN_DIR/composer-output.txt"

  STAGE="clear Laravel config"
  run_artisan "$RUN_DIR/config-clear-output.txt" config:clear
  STAGE="verify effective Laravel configuration"
  load_effective_config
  check "Laravel's effective configuration is rehearsal-only" chk_effective_config

  if (( MAINTENANCE )); then
    STAGE="maintenance mode"; artisan down --retry=60 && WENT_DOWN=1
  fi

  STAGE="SQLite integrity (before)"
  check "rehearsal DB integrity_check" chk_sqlite_sound "$DB_FILE" "the rehearsal DB"
  FK_BEFORE=$(fk_violations); log "foreign-key violations already in the copy: $FK_BEFORE"

  STAGE="snapshot of the rehearsal DB"
  local snap="$RUN_DIR/rehearsal-db-before-migrate.sqlite"
  sqlite3 -readonly "$DB_FILE" ".backup '$snap'"
  chmod 600 "$snap"
  chk_sqlite_sound "$snap" "the snapshot" || refuse "snapshot is not sound"
  sha256sum "$snap" >"$snap.sha256"
  pass "snapshot taken: $snap"

  if (( SANITIZE )); then
    STAGE="sanitize copied queues (copy only)"
    [[ $(real "$DB_FILE") != "$(real "$PROD_DB")" ]] || refuse "refusing to sanitize: DB is the production DB"
    log "SANITIZING THE REHEARSAL COPY ONLY: jobs, failed_jobs and pending partner webhook deliveries"
    sqlite3 "$DB_FILE" "
      delete from jobs; delete from failed_jobs;
      update partner_webhook_deliveries set status='cancelled' where status in ('pending','retrying');" 2>&1 | tee -a "$RUN_DIR/sanitize.log"
  else
    local pend
    pend=$(sql "select (select count(*) from jobs)+(select count(*) from failed_jobs)+(select count(*) from partner_webhook_deliveries where status in ('pending','retrying'));" 2>/dev/null || echo 0)
    (( pend == 0 )) || warn "the copy holds $pend queued jobs/pending webhook deliveries; queue=null means nothing will run them (use --sanitize-copy-queues to empty them in the copy)"
  fi

  STAGE="counts before migrations"
  capture_counts "$RUN_DIR/pre-migrate.counts"
  prod_compare

  STAGE="migrations"
  run_artisan "$RUN_DIR/migration-status-before.txt" migrate:status || true
  run_artisan "$RUN_DIR/migration-pretend.txt" migrate --pretend --force || true
  if grep -Eiq '\b(drop[[:space:]]+(table|column|index)|delete[[:space:]]+from|truncate)\b' "$RUN_DIR/migration-pretend.txt"; then
    if (( ALLOW_DESTRUCTIVE )); then warn "pending migrations contain DROP/DELETE statements (allowed by flag)"
    else refuse "pending migrations contain DROP/DELETE/TRUNCATE (see migration-pretend.txt); re-run with --allow-destructive-migrations once reviewed"; fi
  fi
  run_artisan "$RUN_DIR/migrate-output.txt" migrate --force
  run_artisan "$RUN_DIR/migration-status-after.txt" migrate:status
  if grep -Eq '\bPending\b' "$RUN_DIR/migration-status-after.txt"; then refuse "migrations are still pending after migrate"; fi
  capture_counts "$RUN_DIR/post-migrate.counts"
  check "rehearsal DB integrity_check (after migrations)" chk_sqlite_sound "$DB_FILE" "the rehearsal DB"
  done_stage "prepare (composer, config, snapshot, counts, migrations)"
}

# Documents' real URLs: 1-byte range requests, nothing is stored.
probe_documents() {
  STAGE="probe Base44 document URLs"
  local urls="$RUN_DIR/document-urls.txt"
  EXPORT_DIR="$EXPORT_DIR" php -r '
    $d = getenv("EXPORT_DIR"); $out = [];
    $read = function (string $f) use ($d) {
      $h = fopen("$d/$f", "r"); $hdr = fgetcsv($h, 0, ",", "\"", ""); $rows = [];
      while (($r = fgetcsv($h, 0, ",", "\"", "")) !== false) { if (count($r) === count($hdr)) { $rows[] = array_combine($hdr, $r); } }
      return $rows;
    };
    foreach ($read("Dateianhang_export.csv") as $r) { if (str_starts_with($r["auftrag_id"], "FAHRZEUG_IMPORT_")) { continue; } $out[] = $r["speicherort"]; }
    foreach ($read("Auftragskommentar_export.csv") as $r) {
      if (($r["geloescht"] ?? "") === "true") { continue; }
      foreach (json_decode($r["anhaenge"] ?: "[]", true) ?: [] as $a) { if (!empty($a["url"])) { $out[] = $a["url"]; } }
    }
    echo implode("\n", array_unique($out)), "\n";' >"$urls"
  local total=0 u code hist=""
  declare -A seen=()
  while IFS= read -r u; do
    [[ -n $u ]] || continue
    total=$((total + 1))
    if [[ ! $u =~ ^https://([a-z0-9-]+\.)*base44\.app/ ]]; then code="blocked-host"
    else code=$(curl -s -o /dev/null -m 25 -r 0-0 -w '%{http_code}' -- "$u" || echo "curl-error"); fi
    seen[$code]=$(( ${seen[$code]:-0} + 1 ))
  done <"$urls"
  { echo "document URLs probed: $total (1-byte range requests, nothing stored)"
    for code in "${!seen[@]}"; do echo "  HTTP $code: ${seen[$code]}"; done | sort; } | tee "$RUN_DIR/compare-document-probe.txt"
  rm -f "$urls"
  if [[ -n ${seen[200]:-}${seen[206]:-} ]] && (( total == ${seen[200]:-0} + ${seen[206]:-0} )); then pass "all $total document URLs answered"
  else warn "not every document URL answered 200/206; the real import reports each failure"; fi
}

stage_dry_run() {
  STAGE="dry-run: snapshot before"
  local docs_before; docs_before=$(doc_file_count)
  capture_counts "$RUN_DIR/pre-dry-run.counts"

  STAGE="legacy:import --dry-run"
  run_artisan "$RUN_DIR/dry-run-output.txt" legacy:import --dry-run --report-path "$RUN_DIR/reports/dry-run"
  local report; report=$(parse_report <"$RUN_DIR/dry-run-output.txt")
  [[ -n $report ]] || refuse "could not read the report path from the dry-run output"

  STAGE="dry-run: prove nothing changed"
  capture_counts "$RUN_DIR/post-dry-run.counts"
  if cmp_counts "$RUN_DIR/pre-dry-run.counts" "$RUN_DIR/post-dry-run.counts" full "$VOLATILE_TABLES" >"$RUN_DIR/compare-dry-run.txt"; then
    pass "the dry run changed no table (counts and row checksums identical)"
  else cat "$RUN_DIR/compare-dry-run.txt"; refuse "the dry run changed the database"; fi
  [[ $(doc_file_count) == "$docs_before" ]] || refuse "the dry run changed the documents folder"
  pass "the dry run stored no files"

  local csv="$report/reconciliation.csv"
  log "dry-run report rows: imported=$(count_action "$csv" imported) skipped=$(count_action "$csv" skipped) archived=$(count_action "$csv" archived) review=$(count_action "$csv" review) warning=$(count_action "$csv" warning) planned_downloads=$(count_action "$csv" planned)"
  (( PROBE_DOCUMENTS )) && probe_documents
  done_stage "dry-run"
}

stage_full() {
  local docs_before; docs_before=$(doc_file_count)

  STAGE="legacy:import (real)"
  run_artisan "$RUN_DIR/import-output.txt" legacy:import --report-path "$RUN_DIR/reports/import-1"
  local b1 r1; b1=$(parse_batch <"$RUN_DIR/import-output.txt"); r1=$(parse_report <"$RUN_DIR/import-output.txt")
  [[ -n $b1 ]] || refuse "could not read the batch id from the import output"
  BATCHES+=("$b1"); log "batch 1: $b1"
  FAILED_DOCS=$(count_action "$r1/reconciliation.csv" failed)
  log "documents: imported=$(grep -cE '^dokument,.*,imported,' "$r1/reconciliation.csv" || true) failed=$FAILED_DOCS"
  capture_counts "$RUN_DIR/post-import.counts"
  check "rehearsal DB integrity_check (after import)" chk_sqlite_sound "$DB_FILE" "the rehearsal DB"
  (( $(fk_violations) <= FK_BEFORE )) || refuse "the import introduced foreign-key violations"

  STAGE="legacy:reconcile"
  run_artisan "$RUN_DIR/reconcile-output.txt" legacy:reconcile --report-path "$RUN_DIR/reports/reconcile"
  pass "legacy:reconcile exited 0"

  STAGE="second legacy:import (idempotency)"
  run_artisan "$RUN_DIR/import-output-2.txt" legacy:import --report-path "$RUN_DIR/reports/import-2"
  local b2 r2; b2=$(parse_batch <"$RUN_DIR/import-output-2.txt"); r2=$(parse_report <"$RUN_DIR/import-output-2.txt")
  [[ -z $b2 || $b2 == "$b1" ]] || BATCHES+=("$b2")
  capture_counts "$RUN_DIR/post-import-2.counts"
  local ignore_sum="$VOLATILE_TABLES|legacy_import_map"
  if (( FAILED_DOCS > 0 )); then
    warn "$FAILED_DOCS document downloads failed in the first run, so the second run retries them; document tables are excluded from the idempotency comparison"
    ignore_sum+="|vehicle_report_documents|leasyback_order_attachments"
  fi
  if cmp_counts "$RUN_DIR/post-import.counts" "$RUN_DIR/post-import-2.counts" full "$ignore_sum" >"$RUN_DIR/compare-idempotency.txt"; then
    pass "second import: no table gained, lost or changed a row"
  else cat "$RUN_DIR/compare-idempotency.txt"; refuse "the second import changed the database (not idempotent)"; fi
  local again; again=$(count_action "$r2/reconciliation.csv" imported)
  if (( FAILED_DOCS > 0 )); then again=$(grep -E ',imported,' "$r2/reconciliation.csv" | grep -vcE '^dokument,' || true); fi
  (( again == 0 )) || refuse "the second import reported $again newly imported rows"
  pass "second import reported zero new rows"

  STAGE="legacy:rollback --dry-run"
  local docs_pre_rollback; docs_pre_rollback=$(doc_file_count)
  run_artisan "$RUN_DIR/rollback-dry-run-output.txt" legacy:rollback "$b1" --dry-run --report-path "$RUN_DIR/reports/rollback-dry-run"
  capture_counts "$RUN_DIR/post-rollback-dry-run.counts"
  if cmp_counts "$RUN_DIR/post-import-2.counts" "$RUN_DIR/post-rollback-dry-run.counts" full "$VOLATILE_TABLES" >"$RUN_DIR/compare-rollback-dry-run.txt"; then
    pass "the rollback dry run changed nothing"
  else cat "$RUN_DIR/compare-rollback-dry-run.txt"; refuse "the rollback dry run changed the database"; fi
  [[ $(doc_file_count) == "$docs_pre_rollback" ]] || refuse "the rollback dry run deleted or added stored files"
  pass "the rollback dry run left every stored file in place"

  STAGE="legacy:rollback (real)"
  : >"$RUN_DIR/rollback-output.txt"
  local i
  for (( i=${#BATCHES[@]}-1; i>=0; i-- )); do
    log "rolling back batch ${BATCHES[$i]}"
    run_artisan "$RUN_DIR/rollback-output.tmp" legacy:rollback "${BATCHES[$i]}" --report-path "$RUN_DIR/reports/rollback-$i"
    cat "$RUN_DIR/rollback-output.tmp" >>"$RUN_DIR/rollback-output.txt"; rm -f "$RUN_DIR/rollback-output.tmp"
  done

  STAGE="verify the database is back to its pre-import state"
  capture_counts "$RUN_DIR/post-rollback.counts"
  if cmp_counts "$RUN_DIR/post-migrate.counts" "$RUN_DIR/post-rollback.counts" full "$VOLATILE_TABLES" >"$RUN_DIR/compare-rollback.txt"; then
    pass "after rollback every table matches the post-migration state (counts and row checksums)"
  else cat "$RUN_DIR/compare-rollback.txt"; refuse "the rehearsal DB is NOT back to its pre-import state (see compare-rollback.txt)"; fi
  [[ $(doc_file_count) == "$docs_before" ]] && pass "stored documents are back to $docs_before files" || refuse "documents folder differs from before the import"
  check "rehearsal DB integrity_check (after rollback)" chk_sqlite_sound "$DB_FILE" "the rehearsal DB"
  (( $(fk_violations) <= FK_BEFORE )) || refuse "foreign-key violations after rollback"
  done_stage "full (import, reconcile, idempotency, rollback)"
}

# =============================================================================
# Entry point
# =============================================================================
usage() { sed -n '2,34p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; }

parse_args() {
  [[ $# -ge 1 ]] || { usage; exit 64; }
  MODE=$1; shift
  case $MODE in check|dry-run|full) ;; -h|--help) usage; exit 0;; *) echo "unknown mode '$MODE'" >&2; usage; exit 64;; esac
  while [[ $# -gt 0 ]]; do
    case $1 in
      --confirm) CONFIRM=1;;
      --skip-prod-compare) SKIP_PROD_COMPARE=1;;
      --probe-documents) PROBE_DOCUMENTS=1;;
      --maintenance) MAINTENANCE=1;;
      --with-dev) WITH_DEV=1;;
      --sanitize-copy-queues) SANITIZE=1;;
      --allow-destructive-migrations) ALLOW_DESTRUCTIVE=1;;
      --log-root) LOG_ROOT=${2:?--log-root needs a path}; shift;;
      *) echo "unknown option '$1'" >&2; usage; exit 64;;
    esac
    shift
  done
  if [[ $MODE == full && $CONFIRM != 1 ]]; then
    echo "refusing: 'full' really imports and rolls back; pass --confirm to proceed" >&2; exit 64
  fi
  if [[ $MODE != full && $CONFIRM == 1 ]]; then
    echo "refusing: --confirm only belongs to 'full'" >&2; exit 64
  fi
}

main() {
  parse_args "$@"
  REPO=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)
  ENV_FILE="$REPO/.env"
  FK_BEFORE=0

  # Production is refused before anything is written anywhere.
  chk_not_production_dir || refuse "working directory is the production tree"
  [[ -f $ENV_FILE ]] || refuse ".env not found in $REPO"
  check "log root is outside both repositories" chk_log_root
  init_run_dir
  trap 'on_err $LINENO' ERR
  trap on_exit EXIT

  run_static_checks
  record_environment
  record_export_hashes

  case $MODE in
    check)   done_stage "check" ;;
    dry-run) stage_prepare; stage_dry_run ;;
    full)    stage_prepare; stage_dry_run; stage_full ;;
  esac

  verify_export_hashes
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  main "$@"
fi
