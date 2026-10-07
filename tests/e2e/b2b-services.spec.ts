import type { Locator, Page } from '@playwright/test';
import { BUSY_PLATE, expect, FREE_PLATES, submitLogin, test, USERS } from './fixtures';

/** Dashboard catalogue → vehicle picker → the service's own form. */
async function startService(page: Page, service: RegExp, plate: string) {
    await page.goto('/dashboard');
    await page.getByRole('button', { name: service }).first().click();

    await page.getByRole('button', { name: new RegExp(`^${plate}`) }).click();
    await page.getByRole('button', { name: 'Weiter', exact: true }).click();
}

/** A custom combobox: open it by its placeholder, then choose the option. */
async function choose(page: Page, scope: Locator, placeholder: string, option: string) {
    await scope.getByRole('combobox').filter({ hasText: placeholder }).click();
    await page.getByRole('option', { name: option, exact: true }).click();
}

/** Street, number, postcode, city of the n-th address block in the form. */
async function fillAddress(scope: Locator, nth: number, address: { street: string; number: string; zip: string; city: string }) {
    await scope.getByPlaceholder('Straße *').nth(nth).fill(address.street);
    await scope.getByPlaceholder('Hausnummer').nth(nth).fill(address.number);
    await scope.getByPlaceholder('PLZ *').nth(nth).fill(address.zip);
    await scope.getByPlaceholder('Ort *').nth(nth).fill(address.city);
}

const BERLIN = { street: 'Invalidenstraße', number: '5', zip: '10115', city: 'Berlin' };
const MUNICH = { street: 'Marienplatz', number: '1', zip: '80331', city: 'München' };

/**
 * Opens the date field, moves to next month and picks the 15th — always in the
 * future. The popover animates in and its grid re-renders on the month change,
 * which Playwright's actionability checks can race (measured: for a person the
 * popover is steady once open), so the whole pick is retried as one unit and
 * proven by the date appearing in the field.
 */
async function pickDate(page: Page, scope: Page | Locator = page) {
    const trigger = scope.getByRole('button', { name: /TT\.MM\.JJJJ|\d{2}\.\d{2}\.\d{4}/ }).first();

    await expect(async () => {
        if ((await page.locator('[data-slot="popover-content"]').count()) === 0) {
            await trigger.click({ timeout: 2_000 });
        }

        const calendar = page.locator('[data-slot="popover-content"]').last();
        await calendar.getByRole('button', { name: 'Nächster Monat' }).first().click({ timeout: 2_000 });
        await calendar.locator('button:enabled', { hasText: /^15$/ }).first().click({ timeout: 2_000 });
        await expect(trigger).toHaveText(/15\.\d{2}\.\d{4}/, { timeout: 2_000 });
    }).toPass({ timeout: 20_000 });
}

test.describe('booking every B2B service as a Standard User', () => {
    test.beforeEach(async ({ page }) => {
        await submitLogin(page, USERS.standard);
        await expect(page).toHaveURL(/\/dashboard/);
    });

    test('Leasingrückgabe', async ({ page }) => {
        await startService(page, /^Leasingrückgabe/, FREE_PLATES[0]);

        const form = page.getByRole('dialog', { name: /Abholung beauftragen/ });
        await pickDate(page, form);
        await form.locator('select').first().selectOption({ index: 1 });

        const created = page.waitForResponse((r) => r.request().method() === 'POST' && /\/orders?\b/.test(r.url()));
        await form.getByRole('button', { name: 'Bestätigen' }).click();
        expect((await created).status()).toBeLessThan(400);
        await expect(page.getByText('Abholung wurde angefragt.')).toBeVisible();
    });

    test('Überführung', async ({ page }) => {
        await startService(page, /^Überführung/, FREE_PLATES[1]);

        const form = page.getByRole('dialog', { name: /Überführung beauftragen/ });
        await fillAddress(form, 0, BERLIN);
        await fillAddress(form, 1, MUNICH);
        await pickDate(page, form);
        await choose(page, form, 'Startzeit wählen', '08:00');
        await choose(page, form, 'Endzeit wählen', '12:00');

        await form.getByRole('button', { name: 'Auftrag erstellen' }).click();
        await expect(form).toBeHidden();
        await expect(page.getByText(/Überführung.*(beauftragt|angefragt|erstellt)/i).first()).toBeVisible();
    });

    test('Gutachten', async ({ page }) => {
        await startService(page, /^Gutachten/, FREE_PLATES[2]);

        const form = page.getByRole('dialog', { name: /Gutachten beauftragen/ });
        // The billing address is prefilled from the company default; the vehicle's location is not.
        await fillAddress(form, 1, BERLIN);
        await form.getByPlaceholder('z. B. VW Leasing, Mercedes Bank').fill('VW Leasing');
        await pickDate(page, form);
        await choose(page, form, 'Startzeit wählen', '08:00');
        await choose(page, form, 'Endzeit wählen', '12:00');

        await form.getByRole('button', { name: 'Gutachtenauftrag erstellen' }).click();
        await expect(page.getByRole('heading', { level: 1, name: 'Gutachten beauftragt' })).toBeVisible();
    });

    test('Unfallschaden', async ({ page }) => {
        await startService(page, /^Unfallschaden/, FREE_PLATES[3]);

        const form = page.getByRole('dialog', { name: /Unfallschadenmeldung/ });
        await fillAddress(form, 1, BERLIN);

        await form.getByRole('button', { name: 'Auftrag erstellen' }).click();
        await expect(page.getByRole('heading', { level: 1, name: 'Unfallschaden beauftragt' })).toBeVisible();
    });

    test('a vehicle already in a process is not offered again', async ({ page }) => {
        await page.getByRole('button', { name: /^Leasingrückgabe/ }).click();
        await expect(page.getByRole('dialog').getByRole('button', { name: new RegExp(FREE_PLATES[4]) })).toBeVisible();
        await expect(page.getByRole('dialog').getByText(BUSY_PLATE)).toHaveCount(0);
    });
});
