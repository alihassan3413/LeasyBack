import type { Page } from '@playwright/test';
import { expect, loginAs, submitLogin, test, USERS } from './fixtures';

const OFFER_PLATE = 'B-EA 1098';

/** The fleet row of the vehicle with the published offer, expanded in place (a click on the row). */
async function openOfferRow(page: Page) {
    await page.goto('/fahrzeuge');
    await page.getByRole('row').filter({ hasText: OFFER_PLATE }).filter({ visible: true }).first().getByText(OFFER_PLATE).click();
    // The fleet page also renders a CSS-hidden mobile card with its own copy of the panel.
    await expect(page.getByText('Reparaturangebot', { exact: true }).filter({ visible: true })).toHaveCount(1);
}

test.describe('a published repair offer', () => {
    test('a Read-only member sees it but cannot decide on it', async ({ page }) => {
        await submitLogin(page, USERS.readOnly);
        await openOfferRow(page);

        await expect(page.getByText('E2E Karosserie GmbH').filter({ visible: true }).first()).toBeVisible();
        await expect(page.getByRole('button', { name: 'Reparatur freigeben' }).filter({ visible: true })).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Angebot ablehnen' }).filter({ visible: true })).toHaveCount(0);
    });

    test('the Company Administrator rejects it with a comment', async ({ page }) => {
        await loginAs(page, USERS.owner);
        await openOfferRow(page);

        // The offer card's own button (the plain offer list below repeats it).
        await page.getByRole('button', { name: 'Angebot ablehnen' }).filter({ visible: true }).first().click();
        await page.getByPlaceholder('Optionale Anmerkung oder Rückfrage...').filter({ visible: true }).first().fill('Zu teuer');

        const posted = page.waitForResponse((response) => response.request().method() === 'POST' && /\/offers\/[^/]+\/reject/.test(response.url()));
        await page.getByRole('button', { name: 'Ablehnung bestätigen' }).filter({ visible: true }).first().click();
        expect((await posted).status()).toBeLessThan(400);

        await expect(page.getByText('Sie haben dieses Angebot abgelehnt.').filter({ visible: true }).first()).toBeVisible();
        await expect(page.getByRole('button', { name: 'Reparatur freigeben' }).filter({ visible: true })).toHaveCount(0);
    });
});
