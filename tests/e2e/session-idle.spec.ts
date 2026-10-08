import type { Page } from '@playwright/test';
import { expect, FREE_PLATES, submitLogin, test, USERS } from './fixtures';

/**
 * The inactivity warning ("Sind Sie noch da?") over a booking modal that is
 * already open. It used to be rendered outside Reka's layer stack, so with a
 * modal open it could not be clicked or focused and the user was logged out
 * whatever they did.
 *
 * The page clock is faked: five minutes of inactivity pass in a call.
 */
const stayButton = (page: Page) => page.getByRole('button', { name: 'Angemeldet bleiben' });
const warning = (page: Page) => page.getByRole('alertdialog', { name: 'Sind Sie noch da?' });
const bookingForm = (page: Page) => page.getByRole('dialog', { name: /Abholung beauftragen/ });

/** Dashboard → Leasingrückgabe → vehicle → the booking form, with a time slot chosen (state to preserve). */
async function openBookingForm(page: Page) {
    await page.getByRole('button', { name: /^Leasingrückgabe/ }).click();
    await page.getByRole('button', { name: new RegExp(`^${FREE_PLATES[5]}`) }).click();
    await page.getByRole('button', { name: 'Weiter', exact: true }).click();
    await expect(bookingForm(page)).toBeVisible();
    await bookingForm(page).locator('select').first().selectOption({ index: 2 });
}

async function idleUntilWarning(page: Page) {
    await page.clock.fastForward('05:05');
    await expect(warning(page)).toBeVisible();
}

test.describe('inactivity warning', () => {
    test.beforeEach(async ({ page }) => {
        await page.clock.install();
        await submitLogin(page, USERS.standard);
        await expect(page).toHaveURL(/\/dashboard/);
    });

    test('"Angemeldet bleiben" works over an open booking modal and keeps it as it was', async ({ page }) => {
        await openBookingForm(page);
        const chosenSlot = await bookingForm(page).locator('select').first().inputValue();

        await idleUntilWarning(page);

        const keepAlive = page.waitForResponse((response) => response.url().includes('/session/keep-alive'));
        await stayButton(page).click();
        expect((await keepAlive).status()).toBe(200);

        await expect(warning(page)).toBeHidden();
        await expect(bookingForm(page)).toBeVisible();
        await expect(bookingForm(page).locator('select').first()).toHaveValue(chosenSlot);

        // The clock restarted: a full countdown later the user is still signed in.
        await page.clock.fastForward('01:30');
        await expect(warning(page)).toBeHidden();
        await expect(page).toHaveURL(/\/dashboard/);
        expect((await page.request.get('/dashboard', { maxRedirects: 0 })).status()).toBe(200);
    });

    test('the warning takes keyboard focus and hands it back to the booking modal', async ({ page }) => {
        await openBookingForm(page);
        await idleUntilWarning(page);

        // The safe choice is focused, and Tab stays inside the warning.
        await expect(stayButton(page)).toBeFocused();
        await page.keyboard.press('Tab');
        await expect(page.getByRole('button', { name: 'Jetzt abmelden' })).toBeFocused();
        await page.keyboard.press('Tab');
        await expect(stayButton(page)).toBeFocused();

        // Escape does not dismiss it: an explicit choice is wanted.
        await page.keyboard.press('Escape');
        await expect(warning(page)).toBeVisible();

        const keepAlive = page.waitForResponse((response) => response.url().includes('/session/keep-alive'));
        await page.keyboard.press('Enter');
        expect((await keepAlive).status()).toBe(200);

        await expect(warning(page)).toBeHidden();
        await expect(bookingForm(page)).toBeVisible();
        // Focus is back inside the booking modal, not lost to <body>.
        expect(await bookingForm(page).evaluate((dialog) => dialog.contains(document.activeElement))).toBe(true);
    });

    test('"Jetzt abmelden" still signs the user out, even over a modal', async ({ page }) => {
        await openBookingForm(page);
        await idleUntilWarning(page);

        await page.getByRole('button', { name: 'Jetzt abmelden' }).click();

        await page.waitForURL((url) => !url.pathname.startsWith('/dashboard'), { timeout: 20_000 });
        expect((await page.request.get('/dashboard', { maxRedirects: 0 })).status()).toBe(302);
    });

    test('without any modal open the warning works as before', async ({ page }) => {
        await idleUntilWarning(page);

        const keepAlive = page.waitForResponse((response) => response.url().includes('/session/keep-alive'));
        await stayButton(page).click();
        expect((await keepAlive).status()).toBe(200);
        await expect(warning(page)).toBeHidden();
    });
});
