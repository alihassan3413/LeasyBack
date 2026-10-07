import { expect, test, type Page } from '@playwright/test';

/**
 * "Später fertigstellen" and "Jetzt überspringen" on the B2B registration page.
 * Both must leave for Mein Konto — the dashboard sends a Firmenkunde without a
 * company straight back to this page — and must never submit the form, so
 * skipping works however much of it is filled in.
 */

async function open(page: Page) {
    await page.goto('/b2b-registration.html');
    await page.waitForSelector('#wrap');
}

const submissions = (page: Page) =>
    page.evaluate(() => (window as unknown as { fixtureSubmissions: { url: string }[] }).fixtureSubmissions.map((s) => s.url));

const skipNow = (page: Page) => page.getByRole('link', { name: 'Jetzt überspringen' });
const finishLater = (page: Page) => page.getByRole('link', { name: 'Später fertigstellen' });

test.describe('skipping the registration', () => {
    test('both escape hatches lead to Mein Konto, not the dashboard', async ({ page }) => {
        await open(page);

        await expect(skipNow(page)).toHaveAttribute('href', '/profile.edit');
        await expect(finishLater(page)).toHaveAttribute('href', '/profile.edit');
    });

    for (const [label, link] of [
        ['Jetzt überspringen', skipNow],
        ['Später fertigstellen', finishLater],
    ] as const) {
        test(`"${label}" on an empty form navigates away without submitting`, async ({ page }) => {
            await open(page);

            await Promise.all([page.waitForURL('**/profile.edit'), link(page).click()]);

            expect(new URL(page.url()).pathname).toBe('/profile.edit');
        });

        test(`"${label}" on a half-filled form does not submit or validate it`, async ({ page }) => {
            await open(page);
            await page.locator('#company_name').fill('James GmbH');
            await page.locator('#company_number').fill('12');

            const before = await submissions(page);
            await Promise.all([page.waitForURL('**/profile.edit'), link(page).click()]);

            expect(before).toEqual([]);
            expect(new URL(page.url()).pathname).toBe('/profile.edit');
        });
    }

    test('the skip links are not form buttons', async ({ page }) => {
        await open(page);

        for (const link of [skipNow(page), finishLater(page)]) {
            expect(await link.evaluate((el) => el.tagName)).toBe('A');
            expect(await link.evaluate((el) => el.closest('button'))).toBeNull();
        }
    });
});

test.describe('registering', () => {
    test('"Jetzt Registrieren" still submits the form to onboarding.b2b.store', async ({ page }) => {
        await open(page);

        await page.getByRole('button', { name: 'Jetzt Registrieren' }).click();

        expect(await submissions(page)).toEqual(['/onboarding.b2b.store']);
        expect(new URL(page.url()).pathname).toBe('/b2b-registration.html');
    });
});
