#!/usr/bin/env bash
#
# One-time Linode server provisioning for the Leasyback backend.
# Target: a fresh Ubuntu 22.04 / 24.04 Linode. Run once, as root:
#
#   ssh root@172.105.74.98
#   apt update && apt install -y git
#   git clone https://github.com/alihassan3413/LeasyBack.git /var/www/LeasyBack
#   cd /var/www/LeasyBack/deploy
#   cp config.example.sh config.sh && nano config.sh
#   bash provision.sh
#
# Safe to re-run: every step checks before it changes anything.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [[ ! -f "${SCRIPT_DIR}/config.sh" ]]; then
    echo "ERROR: ${SCRIPT_DIR}/config.sh not found."
    echo "       cp ${SCRIPT_DIR}/config.example.sh ${SCRIPT_DIR}/config.sh and edit it first."
    exit 1
fi

# shellcheck source=config.example.sh
source "${SCRIPT_DIR}/config.sh"

if [[ "${EUID}" -ne 0 ]]; then
    echo "ERROR: provision.sh must run as root (use: sudo bash provision.sh)."
    exit 1
fi

step() { echo -e "\n\033[1;34m==>\033[0m \033[1m$1\033[0m"; }
info() { echo "    $1"; }
warn() { echo -e "    \033[1;33mWARNING:\033[0m $1"; }

export DEBIAN_FRONTEND=noninteractive

# ---------------------------------------------------------------------------
step "Installing base packages"
apt-get update -qq
apt-get install -y -qq \
    software-properties-common curl git unzip zip ca-certificates \
    supervisor nginx redis-server ufw acl sqlite3 >/dev/null
info "base packages ready"

# ---------------------------------------------------------------------------
step "Installing PHP ${PHP_VERSION} + extensions"
if ! grep -rq "ondrej/php" /etc/apt/sources.list.d/ 2>/dev/null; then
    add-apt-repository -y ppa:ondrej/php >/dev/null
    apt-get update -qq
fi
apt-get install -y -qq \
    "php${PHP_VERSION}-fpm" "php${PHP_VERSION}-cli" "php${PHP_VERSION}-common" \
    "php${PHP_VERSION}-mbstring" "php${PHP_VERSION}-xml" \
    "php${PHP_VERSION}-curl" "php${PHP_VERSION}-zip" "php${PHP_VERSION}-gd" \
    "php${PHP_VERSION}-bcmath" "php${PHP_VERSION}-intl" "php${PHP_VERSION}-gmp" \
    "php${PHP_VERSION}-sqlite3" "php${PHP_VERSION}-redis" "php${PHP_VERSION}-opcache" >/dev/null
info "php$(php${PHP_VERSION} -r 'echo PHP_VERSION;') installed"

step "Applying production PHP settings"
cat >"/etc/php/${PHP_VERSION}/fpm/conf.d/99-leasyback.ini" <<EOF
; Managed by deploy/provision.sh
memory_limit = ${PHP_MEMORY_LIMIT}
upload_max_filesize = ${PHP_UPLOAD_MAX}
post_max_size = ${PHP_UPLOAD_MAX}
max_execution_time = 120
max_input_time = 120
expose_php = Off
display_errors = Off
log_errors = On

opcache.enable = 1
opcache.memory_consumption = 192
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 0
opcache.interned_strings_buffer = 16
EOF
cp "/etc/php/${PHP_VERSION}/fpm/conf.d/99-leasyback.ini" \
   "/etc/php/${PHP_VERSION}/cli/conf.d/99-leasyback.ini"
# CLI (artisan, queue workers) must always read fresh code.
sed -i 's/^opcache.validate_timestamps = 0/opcache.validate_timestamps = 1/' \
   "/etc/php/${PHP_VERSION}/cli/conf.d/99-leasyback.ini"
info "ini override written (opcache timestamps off for fpm — deploy.sh reloads fpm)"

# ---------------------------------------------------------------------------
step "Installing Composer"
if ! command -v composer >/dev/null 2>&1; then
    curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
    "php${PHP_VERSION}" /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer >/dev/null
    rm -f /tmp/composer-setup.php
fi
info "composer $(composer --version --no-ansi 2>/dev/null | head -1)"

step "Installing Node.js ${NODE_MAJOR}"
if ! command -v node >/dev/null 2>&1 || [[ "$(node -v)" != v${NODE_MAJOR}.* ]]; then
    curl -fsSL "https://deb.nodesource.com/setup_${NODE_MAJOR}.x" | bash - >/dev/null
    apt-get install -y -qq nodejs >/dev/null
fi
info "node $(node -v), npm $(npm -v)"

# ---------------------------------------------------------------------------
step "Creating deploy user '${DEPLOY_USER}'"
if ! id -u "${DEPLOY_USER}" >/dev/null 2>&1; then
    adduser --disabled-password --gecos "" "${DEPLOY_USER}" >/dev/null
    info "user created (no password — add your SSH key below)"
else
    info "user already exists"
fi
usermod -aG www-data "${DEPLOY_USER}"

# Copy root's authorized_keys so you can SSH in as the deploy user right away.
if [[ -f /root/.ssh/authorized_keys && ! -f "/home/${DEPLOY_USER}/.ssh/authorized_keys" ]]; then
    install -d -m 700 -o "${DEPLOY_USER}" -g "${DEPLOY_USER}" "/home/${DEPLOY_USER}/.ssh"
    install -m 600 -o "${DEPLOY_USER}" -g "${DEPLOY_USER}" \
        /root/.ssh/authorized_keys "/home/${DEPLOY_USER}/.ssh/authorized_keys"
    info "copied root's SSH keys to ${DEPLOY_USER}"
fi

# deploy.sh runs unprivileged but needs to restart services.
cat >/etc/sudoers.d/leasyback-deploy <<EOF
# Managed by deploy/provision.sh — lets deploy.sh restart services without a password.
${DEPLOY_USER} ALL=(root) NOPASSWD: /usr/bin/supervisorctl, /bin/systemctl reload php${PHP_VERSION}-fpm, /usr/bin/systemctl reload php${PHP_VERSION}-fpm, /bin/systemctl reload nginx, /usr/bin/systemctl reload nginx
EOF
chmod 440 /etc/sudoers.d/leasyback-deploy
visudo -cf /etc/sudoers.d/leasyback-deploy >/dev/null

# ---------------------------------------------------------------------------
step "Preparing ${APP_DIR}"
if [[ ! -d "${APP_DIR}/.git" ]]; then
    if [[ -d "${APP_DIR}" && -n "$(ls -A "${APP_DIR}" 2>/dev/null)" ]]; then
        echo "ERROR: ${APP_DIR} exists, is not empty, and is not a git checkout."
        exit 1
    fi
    git clone --branch "${BRANCH}" "${REPO_URL}" "${APP_DIR}"
    info "repository cloned"
else
    info "repository already present"
fi

chown -R "${DEPLOY_USER}:www-data" "${APP_DIR}"
# Skip vendor/, node_modules/ and .git/ — re-running provision.sh must not strip
# the exec bit from vendor/bin/* or node_modules/.bin/* (that breaks npm build).
find "${APP_DIR}" \( -path "${APP_DIR}/vendor" -o -path "${APP_DIR}/node_modules" -o -path "${APP_DIR}/.git" \) -prune -o \
     -type d -exec chmod 2775 {} +
find "${APP_DIR}" \( -path "${APP_DIR}/vendor" -o -path "${APP_DIR}/node_modules" -o -path "${APP_DIR}/.git" \) -prune -o \
     -type f -exec chmod 0664 {} +
chmod +x "${APP_DIR}/artisan" "${SCRIPT_DIR}"/*.sh
chmod -R 2775 "${APP_DIR}/storage" "${APP_DIR}/bootstrap/cache"
# New files created by php-fpm (logs, cache) stay group-writable for the deploy user.
setfacl -R -d -m u:"${DEPLOY_USER}":rwx -m u:www-data:rwx "${APP_DIR}/storage" "${APP_DIR}/bootstrap/cache" 2>/dev/null || true
info "ownership set to ${DEPLOY_USER}:www-data"

# ---------------------------------------------------------------------------
step "Setting up the SQLite database"
SQLITE_DIR="$(dirname "${SQLITE_PATH}")"
install -d -o "${DEPLOY_USER}" -g www-data -m 2775 "${SQLITE_DIR}"

if [[ ! -f "${SQLITE_PATH}" ]]; then
    install -o "${DEPLOY_USER}" -g www-data -m 0664 /dev/null "${SQLITE_PATH}"
    info "created ${SQLITE_PATH}"
else
    info "${SQLITE_PATH} already exists — left untouched"
fi

# SQLite writes -wal/-shm/-journal siblings, so the *directory* must be
# group-writable as well, not just the database file.
chown "${DEPLOY_USER}:www-data" "${SQLITE_PATH}"
chmod 0664 "${SQLITE_PATH}"
chmod 2775 "${SQLITE_DIR}"
setfacl -d -m u:"${DEPLOY_USER}":rwx -m u:www-data:rwx "${SQLITE_DIR}" 2>/dev/null || true
info "writable by ${DEPLOY_USER} and www-data"

# Sessions, cache and the queue all live in this one file, so php-fpm and the
# queue workers write concurrently. WAL is stored in the database header, so
# this survives every connection and deploy.
sudo -u "${DEPLOY_USER}" sqlite3 "${SQLITE_PATH}" "PRAGMA journal_mode=WAL;" >/dev/null
chown "${DEPLOY_USER}:www-data" "${SQLITE_PATH}"*
chmod 0664 "${SQLITE_PATH}"*
info "journal_mode=WAL enabled (concurrent reads while writing)"

step "Setting up .env"
if [[ ! -f "${APP_DIR}/.env" ]]; then
    cp "${SCRIPT_DIR}/env.production.example" "${APP_DIR}/.env"
    chown "${DEPLOY_USER}:www-data" "${APP_DIR}/.env"
    chmod 640 "${APP_DIR}/.env"
    sudo -u "${DEPLOY_USER}" sed -i \
        -e "s|^APP_URL=.*|APP_URL=https://${DOMAIN}|" \
        -e "s|^DB_CONNECTION=.*|DB_CONNECTION=sqlite|" \
        -e "s|^DB_DATABASE=.*|DB_DATABASE=${SQLITE_PATH}|" \
        -e "s|^SESSION_DOMAIN=.*|SESSION_DOMAIN=${DOMAIN}|" \
        -e "s|^FRONTEND_URL=.*|FRONTEND_URL=https://${DOMAIN}|" \
        -e "s|^SANCTUM_STATEFUL_DOMAINS=.*|SANCTUM_STATEFUL_DOMAINS=${DOMAIN}|" \
        -e "s|^REVERB_HOST=.*|REVERB_HOST=${DOMAIN}|" \
        -e "s|^REVERB_SERVER_PORT=.*|REVERB_SERVER_PORT=${REVERB_PORT}|" \
        "${APP_DIR}/.env"
    info ".env created from env.production.example (domain + sqlite path filled in)"
    warn "edit ${APP_DIR}/.env and replace every CHANGE_ME before deploying"
else
    info ".env already exists — left untouched"
fi

if ! grep -qE '^APP_KEY=.+' "${APP_DIR}/.env"; then
    sudo -u "${DEPLOY_USER}" "php${PHP_VERSION}" "${APP_DIR}/artisan" key:generate --force --no-interaction
    info "APP_KEY generated"
fi

# ---------------------------------------------------------------------------
step "Configuring nginx for ${DOMAIN}"
sed -e "s|__DOMAIN__|${DOMAIN}|g" \
    -e "s|__APP_DIR__|${APP_DIR}|g" \
    -e "s|__PHP_VERSION__|${PHP_VERSION}|g" \
    -e "s|__REVERB_PORT__|${REVERB_PORT}|g" \
    -e "s|__UPLOAD_MAX__|${PHP_UPLOAD_MAX}|g" \
    "${SCRIPT_DIR}/nginx/leasyback.conf.template" >/etc/nginx/sites-available/leasyback.conf

ln -sfn /etc/nginx/sites-available/leasyback.conf /etc/nginx/sites-enabled/leasyback.conf
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx
info "nginx serving ${APP_DIR}/public on ${DOMAIN}"

# ---------------------------------------------------------------------------
step "Configuring supervisor (queue workers$( [[ "${RUN_REVERB}" == "true" ]] && echo " + reverb" ))"
sed -e "s|__APP_DIR__|${APP_DIR}|g" \
    -e "s|__PHP_VERSION__|${PHP_VERSION}|g" \
    -e "s|__DEPLOY_USER__|${DEPLOY_USER}|g" \
    -e "s|__QUEUE_WORKERS__|${QUEUE_WORKERS}|g" \
    "${SCRIPT_DIR}/supervisor/leasyback-worker.conf.template" >/etc/supervisor/conf.d/leasyback-worker.conf

if [[ "${RUN_REVERB}" == "true" ]]; then
    sed -e "s|__APP_DIR__|${APP_DIR}|g" \
        -e "s|__PHP_VERSION__|${PHP_VERSION}|g" \
        -e "s|__DEPLOY_USER__|${DEPLOY_USER}|g" \
        -e "s|__REVERB_PORT__|${REVERB_PORT}|g" \
        "${SCRIPT_DIR}/supervisor/leasyback-reverb.conf.template" >/etc/supervisor/conf.d/leasyback-reverb.conf
else
    rm -f /etc/supervisor/conf.d/leasyback-reverb.conf
fi

systemctl enable --now supervisor >/dev/null 2>&1 || true
supervisorctl reread >/dev/null
supervisorctl update
info "supervisor programs installed"

# ---------------------------------------------------------------------------
step "Installing the scheduler cron entry"
CRON_LINE="* * * * * cd ${APP_DIR} && /usr/bin/php${PHP_VERSION} artisan schedule:run >> ${APP_DIR}/storage/logs/schedule.log 2>&1"
CURRENT_CRON="$(crontab -u "${DEPLOY_USER}" -l 2>/dev/null || true)"
if ! grep -Fq "artisan schedule:run" <<<"${CURRENT_CRON}"; then
    printf '%s\n%s\n' "${CURRENT_CRON}" "${CRON_LINE}" | sed '/^$/d' | crontab -u "${DEPLOY_USER}" -
    info "cron installed for ${DEPLOY_USER}"
else
    info "scheduler cron already present"
fi

# ---------------------------------------------------------------------------
step "Configuring the firewall"
ufw allow OpenSSH >/dev/null
ufw allow 'Nginx Full' >/dev/null
ufw --force enable >/dev/null
info "ufw: SSH + HTTP/HTTPS open, everything else closed (Redis and Reverb stay on 127.0.0.1)"

# ---------------------------------------------------------------------------
step "TLS certificate"
if ! command -v certbot >/dev/null 2>&1; then
    apt-get install -y -qq certbot python3-certbot-nginx >/dev/null
fi
info "certbot installed — issue the certificate once ${DOMAIN} points at this server:"
info "    certbot --nginx -d ${DOMAIN} --redirect --agree-tos -m you@leasyback.com"

# ---------------------------------------------------------------------------
step "Provisioning complete"
cat <<EOF

Next steps
----------
1. Point the DNS A record for ${DOMAIN} at 172.105.74.98.
2. Fill in the secrets:      nano ${APP_DIR}/.env      (every CHANGE_ME)
   The database is SQLite at ${SQLITE_PATH} — nothing else to configure.
   Generate Reverb creds:    php${PHP_VERSION} ${APP_DIR}/artisan reverb:install
   Generate VAPID keys:      php${PHP_VERSION} ${APP_DIR}/artisan webpush:vapid
3. Issue the TLS certificate:
       certbot --nginx -d ${DOMAIN} --redirect --agree-tos -m you@leasyback.com
4. Run the first deploy as the deploy user:
       sudo -u ${DEPLOY_USER} bash ${SCRIPT_DIR}/deploy.sh --seed
5. Verify:  curl -I https://${DOMAIN}/up

Every later release is just:  ssh ${DEPLOY_USER}@${DOMAIN} 'bash ${SCRIPT_DIR}/deploy.sh'
EOF
