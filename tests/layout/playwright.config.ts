import { defineConfig, devices } from '@playwright/test';

/**
 * Layout specs for MasonryGrid.
 *
 * These render the component against the real Tailwind build on a static
 * fixture page — no Laravel, no database — so they are kept apart from the
 * application journey suite and its root `playwright.config.ts`. Run them with
 * `npm run test:layout`.
 *
 * Port 4318 so a `composer run dev` or a journey run alongside is untouched.
 */
export const PORT = 4318;
export const BASE_URL = `http://127.0.0.1:${PORT}`;

export default defineConfig({
    testDir: '.',
    testMatch: '*.spec.ts',
    fullyParallel: true,
    retries: 0,
    timeout: 30_000,
    expect: { timeout: 5_000 },
    reporter: [['list']],
    use: {
        baseURL: BASE_URL,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
    webServer: {
        // Relative to this file: Playwright runs webServer from the config's
        // own directory, not the repository root.
        command: `npx vite build -c fixture/vite.config.mts && npx vite preview -c fixture/vite.config.mts --host 127.0.0.1 --port ${PORT} --strictPort`,
        url: BASE_URL,
        reuseExistingServer: false,
        timeout: 120_000,
        stdout: 'pipe',
        stderr: 'pipe',
    },
});
