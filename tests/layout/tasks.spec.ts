import { expect, test, type Page } from '@playwright/test';

/**
 * The Admin tasks card renders priority and follow-ups from the payload alone.
 * These specs hold it to that: the backend's verdict is what shows, the raw
 * enum never is, and a detached follow-up never takes the guided step's place.
 */

const PRIORITY_LABEL = {
    green: 'Im Zeitrahmen',
    yellow: 'Bald fällig',
    red: 'Überfällig',
    immediate_red: 'Sofort',
} as const;

const OVERDUE_LABELS = [PRIORITY_LABEL.red, PRIORITY_LABEL.immediate_red];

function fixtureUrl(params: Record<string, string> = {}): string {
    return `/tasks.html?${new URLSearchParams(params).toString()}`;
}

async function open(page: Page, params: Record<string, string> = {}) {
    await page.goto(fixtureUrl(params));
    await page.waitForSelector('.content-card');
}

test.describe('guided task priority', () => {
    for (const [priority, label] of Object.entries(PRIORITY_LABEL)) {
        test(`${priority} renders its own label`, async ({ page }) => {
            await open(page, { priority });

            await expect(page.getByText(label, { exact: true })).toBeVisible();
        });
    }

    test('neutral shows no priority badge at all', async ({ page }) => {
        await open(page, { priority: 'neutral' });

        for (const label of Object.values(PRIORITY_LABEL)) {
            await expect(page.getByText(label, { exact: true })).toHaveCount(0);
        }
    });

    test('neutral never reads as overdue', async ({ page }) => {
        await open(page, { priority: 'neutral' });

        for (const label of OVERDUE_LABELS) {
            await expect(page.getByText(label, { exact: true })).toHaveCount(0);
        }
    });

    test('immediate red reads as immediate rather than merely overdue', async ({ page }) => {
        await open(page, { priority: 'immediate_red' });

        await expect(page.getByText(PRIORITY_LABEL.immediate_red, { exact: true })).toBeVisible();
        await expect(page.getByText(PRIORITY_LABEL.red, { exact: true })).toHaveCount(0);
    });

    test('the priority is carried by text, not colour alone', async ({ page }) => {
        await open(page, { priority: 'red' });

        const badge = page.getByText(PRIORITY_LABEL.red, { exact: true });

        await expect(badge).toBeVisible();
        await expect(badge).not.toHaveText('');
    });

    test('no backend task key reaches the admin', async ({ page }) => {
        await open(page, { priority: 'red', detached: 'call_customer_about_pending_payment' });

        const body = (await page.locator('body').innerText()).toLowerCase();

        for (const key of ['await_repair_payment', 'call_customer_about_pending_payment', 'immediate_red', 'neutral']) {
            expect(body).not.toContain(key);
        }
    });
});

test.describe('detached follow-ups', () => {
    test('no follow-up section when there are none', async ({ page }) => {
        await open(page, { priority: 'green' });

        await expect(page.locator('[data-role="detached"]')).toHaveCount(0);
    });

    test('the offer follow-up renders in its own section', async ({ page }) => {
        await open(page, { priority: 'neutral', detached: 'call_customer_about_pending_offer' });

        await expect(page.locator('[data-role="detached"]')).toBeVisible();
        await expect(page.getByText('Zusätzliche Follow-ups')).toBeVisible();
        await expect(page.getByText('Kunden zum offenen Angebot anrufen')).toBeVisible();
    });

    test('the payment follow-up renders in its own section', async ({ page }) => {
        await open(page, { priority: 'red', detached: 'call_customer_about_pending_payment' });

        await expect(page.getByText('Kunden zur offenen Zahlung anrufen')).toBeVisible();
        await expect(page.locator('[data-role="detached-task"]')).toHaveCount(1);
    });

    test('several follow-ups render together', async ({ page }) => {
        await open(page, {
            priority: 'red',
            detached: 'call_customer_about_pending_offer,call_customer_about_pending_payment',
        });

        await expect(page.locator('[data-role="detached-task"]')).toHaveCount(2);
    });

    test('a follow-up never replaces the guided next action', async ({ page }) => {
        await open(page, { priority: 'red', detached: 'call_customer_about_pending_payment' });

        await expect(page.getByText('Nächste Aufgabe')).toBeVisible();
        await expect(page.getByText('Zahlungseingang abwarten')).toBeVisible();

        const nextTop = (await page.getByText('Zahlungseingang abwarten').boundingBox())!.y;
        const followUpTop = (await page.locator('[data-role="detached-task"]').first().boundingBox())!.y;

        expect(followUpTop).toBeGreaterThan(nextTop);
    });

    test('follow-ups offer no completion control', async ({ page }) => {
        await open(page, { priority: 'red', detached: 'call_customer_about_pending_payment' });

        const section = page.locator('[data-role="detached"]');

        await expect(section.locator('button')).toHaveCount(0);
        await expect(section.locator('input[type="checkbox"]')).toHaveCount(0);
    });
});

test.describe('invoice and payment', () => {
    test('an outstanding repair shows the amount, the invoice number and the link', async ({ page }) => {
        await open(page, { priority: 'red', billing: 'awaiting' });

        await expect(page.getByText('Rechnung RE-2026-118')).toBeVisible();
        await expect(page.getByText('714,00', { exact: false })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Link kopieren' })).toBeVisible();
        await expect(page.getByText('Bezahlt am')).toHaveCount(0);
    });

    test('a settled repair shows when it was paid and drops the link', async ({ page }) => {
        await open(page, { priority: 'green', billing: 'paid' });

        await expect(page.getByText('Zahlung erhalten')).toBeVisible();
        await expect(page.getByText('Bezahlt am')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Link kopieren' })).toHaveCount(0);
    });

    test('an invoice needing review says so without offering a control', async ({ page }) => {
        await open(page, { priority: 'neutral', billing: 'review' });

        const notice = page.locator('[data-role="needs-review"]');

        await expect(notice).toBeVisible();
        await expect(notice.locator('button')).toHaveCount(0);
    });

    test('no provider identifiers reach the admin', async ({ page }) => {
        await open(page, { priority: 'red', billing: 'awaiting' });

        const body = await page.locator('body').innerText();

        expect(body).not.toContain('plink_');
        expect(body).not.toContain('invoice-1');
        expect(body).not.toContain('needs_reconciliation');
    });
});

test.describe('responsive', () => {
    for (const width of [1440, 768, 390]) {
        test(`stays readable without sideways scrolling at ${width}px`, async ({ page }) => {
            await page.setViewportSize({ width, height: 900 });
            await open(page, { priority: 'red', detached: 'call_customer_about_pending_payment' });

            const overflows = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);

            expect(overflows).toBe(false);
            await expect(page.getByText('Überfällig', { exact: true })).toBeVisible();
            await expect(page.getByText('Zahlungseingang abwarten')).toBeVisible();

            const title = (await page.getByText('Zahlungseingang abwarten').boundingBox())!;
            const badge = (await page.getByText('Überfällig', { exact: true }).boundingBox())!;
            const apart = badge.x >= title.x + title.width - 1 || badge.y >= title.y + title.height - 1 || title.y >= badge.y + badge.height - 1;

            expect(apart).toBe(true);
        });
    }
});
