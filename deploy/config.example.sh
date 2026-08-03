#!/usr/bin/env bash
# Copy this file to deploy/config.sh on the server and edit the values.
#   cp deploy/config.example.sh deploy/config.sh
# config.sh is git-ignored so your server values never get committed.

# --- Application ---------------------------------------------------------
APP_DIR="/var/www/LeasyBack"
REPO_URL="https://github.com/alihassan3413/LeasyBack.git"
BRANCH="main"

# Domain that serves this Laravel app (backend + Inertia admin panel).
# DNS A record must point at the server: 172.105.74.98
DOMAIN="leasyback.insuretechgurus.com"

# Linux user that owns the code and runs deployments.
DEPLOY_USER="deploy"

# --- Runtime versions ----------------------------------------------------
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
PHP_MEMORY_LIMIT="512M"
