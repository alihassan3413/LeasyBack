import { test as base, expect, type Page, type Response } from '@playwright/test';
import { createHmac } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';

/**
 * The B2B suite's `test`: every page it opens is watched, and a test fails
 * after its last step if the page threw, logged an error, warned about a
 * missing required prop, or got a 5xx from the server — so "it rendered" means
 * it rendered cleanly, not merely that a heading appeared.
 *
 * Ignored on purpose, and only these: the realtime websocket (no Reverb server
 * runs in the suite) and Google Places (an external API the suite never calls
 * for real).
 */
const ENVIRONMENTAL = [
    /WebSocket connection to .* failed/i,
    /\/app\/[^/]+\?protocol=/i,
    /places\.googleapis\.com/i,
    /Failed to load resource: net::ERR_CONNECTION_REFUSED/i,
];

export interface PageProblems {
    errors: string[];
    /** For a test that deliberately opens a refused page: the browser logs its 403/404. */
    allow: (pattern: RegExp) => void;
}

export const test = base.extend<{ problems: PageProblems }>({
    problems: [
        async ({ page }, use) => {
            const allowed: RegExp[] = [];
            const problems: PageProblems = { errors: [], allow: (pattern) => allowed.push(pattern) };
            const record = (message: string) => {
                if (!ENVIRONMENTAL.some((pattern) => pattern.test(message))) {
                    problems.errors.push(message.slice(0, 500));
                }
            };

            page.on('pageerror', (error) => record(`pageerror: ${error.message}`));
            page.on('console', (message) => {
                const text = message.text();

                if (message.type() === 'error') {
                    // The source URL lets ENVIRONMENTAL match a failed load ("status of 403") by its host.
                    record(`console.error: ${text} @ ${message.location().url}`);
                } else if (message.type() === 'warning' && /\[Vue warn\].*(Missing required prop|Unhandled error|Invalid prop)/.test(text)) {
                    record(`vue: ${text}`);
                }
            });
            page.on('response', (response: Response) => {
                if (response.status() >= 500) {
                    record(`HTTP ${response.status()} ${response.request().method()} ${response.url()}`);
                }
            });

            await use(problems);

            const unexpected = problems.errors.filter((error) => !allowed.some((pattern) => pattern.test(error)));
            expect(unexpected, 'the pages this test visited reported errors').toEqual([]);
        },
        { auto: true },
    ],
});

export { expect };

// ---------------------------------------------------------------- accounts

export const PASSWORD = 'e2e-password';

/** Mirrors database/seeders/E2eSeeder.php. */
export const USERS = {
    admin: 'e2e.admin@leasyback.test',
    admin2: 'e2e.admin2@leasyback.test',
    admin3: 'e2e.admin3@leasyback.test',
    owner: 'e2e.owner@leasyback.test',
    owner2: 'e2e.owner2@leasyback.test',
    standard: 'e2e.standard@leasyback.test',
    readOnly: 'e2e.readonly@leasyback.test',
    target: 'e2e.target@leasyback.test',
    foreign: 'e2e.foreign@leasyback.test',
    newCompany: 'e2e.newcompany@leasyback.test',
    invitee: 'e2e.invitee@leasyback.test',
} as const;

export const TOTP_SECRETS: Record<string, string> = {
    [USERS.admin]: 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXA',
    [USERS.admin2]: 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXB',
    [USERS.admin3]: 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXC',
    [USERS.owner]: 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXD',
    [USERS.owner2]: 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXE',
};

export const INVITATION_TOKEN = 'e2einvitationtoken0000000000000000000000000000000000000000000001';

export const COMPANY = 'E2E Fleet GmbH';
export const SECOND_COMPANY = 'E2E Zweitfirma AG';
export const FREE_PLATES = ['B-EA 1001', 'B-EA 1002', 'B-EA 1003', 'B-EA 1004', 'B-EA 1005', 'B-EA 1006'];
export const BUSY_PLATE = 'B-EA 1099';
export const FOREIGN_PLATE = 'D-FF 9001';

// -------------------------------------------------------------------- TOTP

function base32Decode(input: string): Buffer {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';

    for (const char of input.replace(/=+$/, '').toUpperCase()) {
        bits += alphabet.indexOf(char).toString(2).padStart(5, '0');
    }

    const bytes: number[] = [];

    for (let i = 0; i + 8 <= bits.length; i += 8) {
        bytes.push(parseInt(bits.slice(i, i + 8), 2));
    }

    return Buffer.from(bytes);
}

/** RFC 6238, as MfaTotpService::codeAt(): SHA-1, 6 digits, 30-second steps. */
export function totp(secret: string, step = Math.floor(Date.now() / 1000 / 30)): string {
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(step));
    const hash = createHmac('sha1', base32Decode(secret)).update(counter).digest();
    const offset = hash[hash.length - 1] & 0x0f;
    const binary = ((hash[offset] & 0x7f) << 24) | (hash[offset + 1] << 16) | (hash[offset + 2] << 8) | hash[offset + 3];

    return String(binary % 1_000_000).padStart(6, '0');
}

// ------------------------------------------------------------------- login

/** Fills the password form; returns once the page has left /login. */
export async function submitLogin(page: Page, email: string, password = PASSWORD) {
    await page.goto('/login');
    await page.getByRole('textbox', { name: /e-mail/i }).fill(email);
    await page.getByRole('textbox', { name: /passwort/i }).fill(password);
    await page.getByRole('button', { name: /^einloggen/i }).click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 20_000 });
}

/**
 * The server refuses a TOTP step at or below the last one an account used
 * (replay protection) and accepts ±1 step. The last step per account is kept
 * in a file, not in memory, because Playwright restarts the worker after a
 * failed test; globalSetup deletes it together with the database.
 */
export const TOTP_STATE_FILE = resolve(process.cwd(), 'test-results/.e2e-totp-steps.json');

async function freshTotp(email: string): Promise<string> {
    const used: Record<string, number> = existsSync(TOTP_STATE_FILE) ? JSON.parse(readFileSync(TOTP_STATE_FILE, 'utf8')) : {};
    const current = Math.floor(Date.now() / 1000 / 30);
    const step = Math.max(current - 1, (used[email] ?? -Infinity) + 1);

    if (step > current + 1) {
        // Every step the server would still accept is spent; wait for the next one.
        await new Promise((done) => setTimeout(done, ((step - 1) * 30 - Date.now() / 1000) * 1000 + 500));
    }

    used[email] = step;
    mkdirSync(dirname(TOTP_STATE_FILE), { recursive: true });
    writeFileSync(TOTP_STATE_FILE, JSON.stringify(used));

    return totp(TOTP_SECRETS[email], step);
}

/** Signs in as a seeded account, passing its authenticator-app challenge when it has one. */
export async function loginAs(page: Page, email: string) {
    await submitLogin(page, email);

    if (new URL(page.url()).pathname === '/mfa/verify') {
        await page.getByTestId('otp-box-0').pressSequentially(await freshTotp(email));
        await page.waitForURL((url) => !url.pathname.startsWith('/mfa'), { timeout: 20_000 });
    }
}
