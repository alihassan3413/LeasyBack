#!/usr/bin/env bash
# Copy this file to deploy/config.sh on the server and edit the values.
#   cp deploy/config.example.sh deploy/config.sh
# config.sh is git-ignored so your server values never get committed.

# --- Application ---------------------------------------------------------
APP_DIR="/var/www/LeasyBack"
REPO_URL="https://github.com/alihassan3413/LeasyBack.git"
# Default branch for --rehearsal. --production never reads it (see PRODUCTION_BRANCH).
BRANCH="main"

# Domain that serves this Laravel app (backend + Inertia admin panel).
# DNS A record must point at the server: 172.105.74.98
DOMAIN="leasyback.insuretechgurus.com"

# Linux user that owns the code and runs deployments.
DEPLOY_USER="deploy"

# --- Runtime versions ----------------------------------------------------
# deploy.sh detects the running php*-fpm version itself. Set PHP_VERSION only
# to pin it (deploy.sh then refuses if that version is not the one running);
# provision.sh needs it to know what to install.
PHP_VERSION="8.4"
NODE_MAJOR="22"

# --- Database (SQLite) ---------------------------------------------------
# provision.sh creates this file only if it is missing, then makes it
# writable by both the deploy user and www-data. Keep it in sync with
# DB_DATABASE in .env. Nginx never serves database/, so the file is not
# reachable over HTTP.
SQLITE_PATH="${APP_DIR}/database/database.sqlite"

# --- Workers -------------------------------------------------------------
# Number of `queue:work` processes supervisor keeps alive.
QUEUE_WORKERS=2

# Run the Laravel Reverb websocket server under supervisor? (true|false)
RUN_REVERB=true
REVERB_PORT=8080

# --- Deploy behaviour ----------------------------------------------------
# Build frontend assets on the server with `npm ci && npm run build`.
# Set to false if you build assets in CI and commit/ship public/build instead.
BUILD_ASSETS=true

# Put the app in maintenance mode during deploy.
MAINTENANCE_MODE=true

# Run `php artisan migrate --force` on every deploy.
RUN_MIGRATIONS=true

# PHP limits written to the php-fpm/cli ini override (vehicle photo uploads).
PHP_UPLOAD_MAX="50M"
PHP_POST_MAX="56M"
PHP_MEMORY_LIMIT="512M"

# --- Deploy modes (deploy.sh --rehearsal | --production) -----------------
# Branch --production deploys when no --branch is given. `--production
# --branch=NAME` deploys NAME instead (it must exist on origin).
PRODUCTION_BRANCH="main"

# The final production host. --production requires APP_URL to use it (falls
# back to DOMAIN above); --rehearsal refuses it, so a rehearsal can never be
# served under the production name. Leave empty until it is known.
PRODUCTION_DOMAIN=""

# APP_ENV values --rehearsal accepts besides `local` (space/comma separated).
# `production` is refused whatever is listed here.
REHEARSAL_ALLOWED_APP_ENVS=""

# Where deploy logs go. Must be outside APP_DIR and /secure/base44-export.
# DEPLOY_LOG_DIR="$HOME/leasyback-deploy-logs"

# Where the pre-migration SQLite backups go, and how many are kept.
# DEPLOY_BACKUP_DIR="${APP_DIR}/storage/app/backups"
# KEEP_BACKUPS=10

# Group php-fpm runs as; storage, bootstrap/cache and the database must belong to it.
# WEB_GROUP="www-data"
