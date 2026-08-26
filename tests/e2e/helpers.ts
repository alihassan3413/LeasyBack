import { expect, type Page } from '@playwright/test';
import { resolve } from 'node:path';

export const CUSTOMER = { email: 'e2e.customer@leasyback.test', password: 'e2e-password' };
export const ADMIN = { email: 'e2e.admin@leasyback.test', password: 'e2e-password' };
export const STATION = 'DEKRA E2E Prüfstelle Berlin';

/**
 * Fields are matched by role rather than by label: the password field shares
 * its label text with the "Passwort anzeigen" reveal button, so getByLabel is
 * ambiguous there and `textbox` is not.
 */
export async function login(page: Page, who: { email: string; password: string }) {
    await page.goto('/login');
    await page.getByRole('textbox', { name: /e-mail/i }).fill(who.email);
    await page.getByRole('textbox', { name: /passwort/i }).fill(who.password);
    await page.getByRole('button', { name: /^einloggen/i }).click();
    await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 20_000 });
}

export async function logout(page: Page) {
    await page.context().clearCookies();
}

export async function loginAs(page: Page, who: { email: string; password: string }) {
    await logout(page);
    await login(page, who);
}

/**
 * The selects in this app are reka-ui, so they are `<button role="combobox">`
 * with a portalled listbox — `selectOption()` does not apply to them.
 */
export async function chooseOption(page: Page, label: string | RegExp, option: string | RegExp) {
    await page.getByRole('combobox', { name: label }).click();
    await page.getByRole('option', { name: option }).first().click();
}

/**
 * SearchableSelectField is a popover with a search box and plain `<button>`
 * options — no listbox role, so `getByRole('option')` finds nothing.
 */
export async function chooseSearchable(page: Page, trigger: string | RegExp, search: string, option: string | RegExp, exact = true) {
    await page.getByRole('button', { name: trigger }).click();
    await page.getByPlaceholder(/suchen/i).fill(search);
    await page.getByRole('button', { name: option, exact: typeof option === 'string' ? exact : undefined }).first().click();
}

/**
 * CalendarDateField renders each day as a plain numbered button, disabled when
 * it is not selectable. Stepping a month forward first guarantees a full grid
 * of future dates, so the pick never lands on a past day.
 */
export async function pickFutureDate(page: Page, trigger: string | RegExp) {
    await page.getByRole('button', { name: trigger }).click();

    // The first enabled cell in the day grid, whatever it is. Navigating by
    // month would be more explicit, but the calendar's month and year arrows
    // carry the same aria-label ("Nächster Monat"), so there is no unambiguous
    // way to ask for one of them.
    await page.locator('.grid-cols-7 button:not([disabled])').first().click();
}

/**
 * The first-visit "Einführung" modal. It is `aria-hidden`-ing the rest of the
 * page while open, so anything underneath is both unclickable and invisible to
 * accessibility queries until it is gone.
 */
export async function dismissWelcome(page: Page) {
    await page.waitForLoadState('networkidle');

    // The backdrop, not the dialog. It is itself `aria-hidden`, so no role
    // query finds it — yet it covers the page and swallows every click, which
    // is what makes an undismissed modal look like a broken page rather than an
    // open one. It also animates in, hence the grace period.
    const backdrop = page.locator('div[data-state="open"].fixed.inset-0').first();

    await backdrop.waitFor({ state: 'visible', timeout: 3_000 }).catch(() => undefined);

    for (let attempt = 0; attempt < 3; attempt++) {
        if (!(await backdrop.isVisible().catch(() => false))) {
            return;
        }

        await page.keyboard.press('Escape');
        await page.waitForTimeout(400);
    }

    await expect(backdrop).toBeHidden({ timeout: 5_000 });
}

export const PLATE = 'B LB 2026E';

/** The admin order page for the E2E vehicle's order. */
export async function openTheOrder(page: Page) {
    await page.goto('/admin/orders');
    await page.getByRole('row').filter({ hasText: PLATE }).first().click();
    await page.waitForURL(/\/admin\/orders\/[0-9a-f-]{36}/, { timeout: 20_000 });
    await page.waitForLoadState('networkidle');
}

/** The headline of the admin "Nächste Aufgabe" card. */
export function nextTaskCard(page: Page) {
    return page.locator('.content-card').filter({ hasText: 'Nächste Aufgabe' });
}

export async function expectNextTask(page: Page, title: string | RegExp) {
    await expect(nextTaskCard(page).getByText(title)).toBeVisible();
}

/**
 * Runs whatever action button the task card is currently offering.
 *
 * Exact, because the card's own title is a button too and several titles
 * contain their action's label — "Begutachtungstermin bestätigen" contains
 * "Termin bestätigen".
 */
export async function runTaskAction(page: Page, label: string) {
    await nextTaskCard(page).getByRole('button', { name: label, exact: true }).click();
    await page.waitForLoadState('networkidle');
}

/** The customer's own vehicle panel, expanded. */
export async function openVehiclePanel(page: Page) {
    await page.goto('/dashboard');
    await page.waitForLoadState('networkidle');
}

/**
 * Uploads and publishes a report document. It lives on the *vehicle* page, not
 * the order page — the task card says "Erstgutachten hochladen" but cannot link
 * to it, so this mirrors the click path an admin actually takes.
 */
export async function uploadReport(page: Page, label: 'Gutachten' | 'Nachgutachten') {
    await page.getByRole('link', { name: /Fahrzeug öffnen/ }).click();
    await page.waitForURL(/\/admin\/vehicles\//, { timeout: 20_000 });

    await page.getByRole('button', { name: 'Gutachten hochladen' }).click();

    await chooseOption(page, /^Auftrag/, /BLB/);
    await chooseOption(page, /Dokumententyp/, label);

    await page.locator('input[type="file"]').first().setInputFiles(resolve(process.cwd(), 'tests/e2e/fixtures/report.pdf'));

    // Published immediately: an unpublished report is a draft the customer
    // cannot see, and the timeline deliberately ignores those.
    await page.getByRole('checkbox', { name: /Sofort für den Kunden freigeben/ }).check();

    await page.getByRole('button', { name: 'Hochladen', exact: true }).click();
    await expect(page.getByRole('dialog')).toBeHidden({ timeout: 20_000 });
}

/**
 * By anchor id, not by text: the workshop-quotation card's own empty-state hint
 * says "Erfassen Sie zuerst die Gutachtenpositionen", so filtering cards by that
 * word matches two of them.
 */
export function positionsCard(page: Page) {
    return page.locator('#order-section-positionen');
}

/**
 * Adds one row to the Gutachtenpositionen card and fills it.
 *
 * Scoped to the card rather than the page: the workshop-quotation card above it
 * carries a "Gültig (Tage)" number input, so page-wide `spinbutton` indices are
 * off by one and the amounts land in the wrong fields — silently, because every
 * field accepts a number.
 */
export async function addPosition(page: Page, index: number, component: string, damage: string, amount: string, method: string) {
    const card = positionsCard(page);

    await card.getByRole('button', { name: 'Position hinzufügen' }).click();

    await card.getByPlaceholder('Bauteil / Position').nth(index).fill(component);
    await card.getByPlaceholder('Schadenbeschreibung (optional)').nth(index).fill(damage);
    await card.getByRole('spinbutton').nth(index * 2).fill(amount);
    await card.getByPlaceholder('z. B. Lackierung, Instandsetzung').nth(index).fill(method);
}

/** The Werkstattangebote card on the admin order page. */
export function quotationsCard(page: Page) {
    return page.locator('.content-card').filter({ hasText: 'Anfragen · Nettopreise' });
}
