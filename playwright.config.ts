import { defineConfig, devices } from '@playwright/test';

/**
 * Browser tests for the B2C journey.
 *
 * The suite runs against a throwaway SQLite file and the `log` mailer, and it
 * reaches them through `APP_ENV=e2e` rather than by exporting DB_DATABASE and
 * MAIL_MAILER directly. That indirection is not stylistic: Laravel's
 * ServeCommand forwards only a whitelist of environment variables to the PHP
 * server it spawns (`ServeCommand::$passthroughVariables`), and neither of
 * those is on it — set directly, they reach `artisan serve` and are dropped
 * before the application ever sees them, so the suite would quietly run
 * against the developer's own database and the live SendGrid credentials.
 * APP_ENV *is* on the list, and Laravel loads `.env.e2e` when it is set, so
 * globalSetup generates that file and everything follows from it.
 *
 * Port 8123 rather than 8000 so a `composer run dev` left running alongside is
 * untouched, and single-worker because every spec shares the one database.
 */
export const PORT = 8123;
export const BASE_URL = `http://127.0.0.1:${PORT}`;
export const E2E_DB = 'database/e2e.sqlite';
export const E2E_ENV_FILE = '.env.e2e';

/** Written into `.env.e2e` on top of a copy of the developer's own `.env`. */
export const E2E_OVERRIDES: Record<string, string> = {
    APP_ENV: 'e2e',
    APP_DEBUG: 'true',
    APP_URL: BASE_URL,
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: E2E_DB,
    MAIL_MAILER: 'log',
    QUEUE_CONNECTION: 'sync',
    BROADCAST_CONNECTION: 'log',
    CACHE_STORE: 'array',
    SESSION_DRIVER: 'file',
    ADMIN_NOTIFICATION_EMAILS: 'e2e.ops@leasyback.test',
    OPS_NOTIFICATION_EMAIL: 'e2e.ops@leasyback.test',
    MAIL_PORTAL_URL: BASE_URL,
};

export default defineConfig({
    testDir: './tests/e2e',
    globalSetup: './tests/e2e/global-setup.ts',
    globalTeardown: './tests/e2e/global-teardown.ts',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    timeout: 90_000,
    expect: { timeout: 15_000 },
    reporter: [['list'], ['html', { open: 'never' }]],
    use: {
        baseURL: BASE_URL,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'off',
        locale: 'de-DE',
        actionTimeout: 15_000,
    },
    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
    webServer: {
        command: `php artisan serve --host=127.0.0.1 --port=${PORT}`,
        url: BASE_URL,
        reuseExistingServer: false,
        timeout: 60_000,
        stdout: 'pipe',
        stderr: 'pipe',
        env: { APP_ENV: 'e2e' },
    },
});
