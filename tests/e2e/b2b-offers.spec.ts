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
    // Each step builds on the last: rejected, then replaced, then shown again.
    test.describe.configure({ mode: 'serial' });

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

    test('the rejected offer stays as history and can no longer be chosen', async ({ page }) => {
        await loginAs(page, USERS.owner);
        await openOfferRow(page);

        // The offer list used to keep a live radio button for it.
        const choice = page.getByTitle('Abgelehnt', { exact: true }).filter({ visible: true });
        await expect(choice).toHaveCount(1);
        await expect(choice).toBeDisabled();
        await expect(page.getByRole('button', { name: 'Reparatur freigeben' }).filter({ visible: true })).toHaveCount(0);
    });

    test("admin's Next Task opens the create-offer flow and publishes a replacement", async ({ page }) => {
        await loginAs(page, USERS.admin2);
        await page.goto('/admin/orders');
        await page.getByText(OFFER_PLATE).filter({ visible: true }).first().click();
        await expect(page).toHaveURL(/\/admin\/orders\/[0-9a-f-]{36}/);

        await expect(page.getByText('Kundenangebot erstellen', { exact: true }).filter({ visible: true }).first()).toBeVisible();
        // The task's own button — it used to do nothing after a rejection.
        await page.getByRole('button', { name: 'Angebot erstellen', exact: true }).filter({ visible: true }).first().click();

        const modal = page.getByRole('dialog');
        await expect(modal.getByText('Werkstattangebot übernehmen')).toBeVisible();
        await modal.getByRole('radio').first().check();

        const created = page.waitForResponse((response) => response.request().method() === 'POST' && /\/b2b-offer/.test(response.url()));
        await modal.getByRole('button', { name: 'Als Kundenangebot erstellen' }).click();
        expect((await created).status()).toBeLessThan(400);
        await expect(modal).toHaveCount(0);

        const published = page.waitForResponse((response) => response.request().method() === 'PATCH' && /\/publish/.test(response.url()));
        await page.getByRole('button', { name: 'Angebot veröffentlichen', exact: true }).filter({ visible: true }).first().click();
        expect((await published).status()).toBeLessThan(400);
    });

    test('the customer sees the replacement as the live offer, the rejected one still marked', async ({ page }) => {
        await loginAs(page, USERS.owner);
        await openOfferRow(page);

        await expect(page.getByRole('button', { name: 'Reparatur freigeben' }).filter({ visible: true }).first()).toBeEnabled();
        await expect(page.getByTitle('Abgelehnt', { exact: true }).filter({ visible: true })).toBeDisabled();
        await expect(page.getByTitle('Angebot auswählen', { exact: true }).filter({ visible: true })).toBeEnabled();
    });
});
