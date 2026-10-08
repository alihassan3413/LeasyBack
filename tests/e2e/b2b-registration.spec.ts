import { expect, PASSWORD, submitLogin, test, totp, USERS } from './fixtures';

test.describe('company registration', () => {
    test('a new Firmenkunde registers, skips the company form for now, and logs out', async ({ page }) => {
        await page.goto('/register');
        await page.getByRole('combobox', { name: /Jetzt registrieren als/ }).click();
        await page.getByRole('option', { name: 'Firmenkunde', exact: true }).click();
        await page.getByRole('textbox', { name: /^E-Mail-Adresse/ }).fill('e2e.registriert@leasyback.test');
        await page.getByRole('textbox', { name: /^Passwort/ }).fill(`${PASSWORD}-Neu1!`);
        await page.getByRole('button', { name: 'Registrieren', exact: true }).click();

        await expect(page).toHaveURL(/\/onboarding\/b2b/);
        await page.getByRole('link', { name: 'Jetzt überspringen' }).click();

        // The pending dashboard: the catalogue to look at, nothing bookable, and the way back.
        await expect(page).toHaveURL(/\/dashboard/);
        await expect(page.getByRole('heading', { name: 'Firmendaten vervollständigen' })).toBeVisible();
        await expect(page.getByRole('button', { name: /^Leasingrückgabe/ })).toHaveCount(0);
        await expect(page.getByText('Firmendaten erforderlich').first()).toBeVisible();

        const nav = page.getByRole('navigation');
        await expect(nav.getByRole('button', { name: 'Mein Dashboard' })).toBeVisible();
        await expect(nav.getByRole('button', { name: 'Fahrzeuge' })).toHaveCount(0);
        await expect(nav.getByRole('button', { name: 'Aufträge' })).toHaveCount(0);

        // A locked page is still locked: straight to registration.
        await page.goto('/fahrzeuge');
        await expect(page).toHaveURL(/\/onboarding\/b2b/);

        // "Später fertigstellen" lands on the same dashboard, and its banner leads back.
        await page.getByRole('link', { name: 'Später fertigstellen' }).click();
        await expect(page).toHaveURL(/\/dashboard/);
        await page.getByRole('link', { name: 'Firmendaten hinterlegen' }).click();
        await expect(page).toHaveURL(/\/onboarding\/b2b/);

        // On a phone the bottom tab bar offers the dashboard too, and the banner is there.
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto('/dashboard');
        await expect(page.getByRole('heading', { name: 'Firmendaten vervollständigen' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Mein Dashboard' }).filter({ visible: true })).toHaveCount(1);
        await page.setViewportSize({ width: 1280, height: 720 });

        // Mein Konto names the account instead of "—" while there is no company.
        await page.goto('/settings/profile');
        await expect(page.getByText('e2e.registriert', { exact: true }).first()).toBeVisible();
        await expect(page.getByText('—', { exact: true })).toHaveCount(0);

        await page
            .getByRole('button', { name: /Ausloggen|Abmelden/ })
            .first()
            .click();
        await expect(page).toHaveURL(/\/(login)?$/);
    });

    test('a Firmenkunde without a company registers one and reaches the dashboard', async ({ page }) => {
        await submitLogin(page, USERS.newCompany);
        await page.goto('/onboarding/b2b');

        await page.getByLabel(/^Firmenname/).fill('E2E Neugründung GmbH');
        await page.getByLabel(/^Straße/).fill('Invalidenstraße');
        await page.getByLabel(/^Nr\./).fill('5');
        await page.getByLabel(/^PLZ/).fill('10115');
        await page.getByLabel(/^Ort/).fill('Berlin');
        await page.getByRole('combobox', { name: /^Anrede/ }).click();
        await page.getByRole('option').first().click();
        await page.getByLabel(/^Vorname/).fill('Nora');
        await page.getByLabel(/^Nachname/).fill('Neu');
        await page.getByLabel(/^E-Mail-Adresse für Anfragen/).fill('anfragen@neugruendung.test');
        // The phone fieldset's input has no label of its own (only a placeholder).
        await page.getByPlaceholder('z. B. 030 12345678').fill('030123456');

        await page.getByRole('button', { name: 'Jetzt Registrieren' }).click();
        await page.waitForURL((url) => !url.pathname.startsWith('/onboarding'), { timeout: 20_000 });

        // The new owner is now a Company Administrator, so MFA is mandatory before anything else.
        await expect(page).toHaveURL(/\/mfa\/setup/);
        await page.getByTestId('mfa-method-totp').click();
        await page.getByTestId('mfa-show-secret').click();
        const secret = (await page.getByTestId('mfa-secret').innerText()).replace(/\s/g, '');
        await page.getByTestId('otp-box-0').pressSequentially(totp(secret));

        await expect(page.getByTestId('mfa-recovery-codes')).toBeVisible();
        await page.getByTestId('mfa-codes-saved').check();
        await page.getByRole('button', { name: /Weiter/ }).click();

        await page.goto('/dashboard');
        await expect(page.getByRole('heading', { level: 2, name: 'Leistungen' })).toBeVisible();
    });
});

test.describe('phone width', () => {
    test.use({ viewport: { width: 390, height: 844 } });

    test('the dashboard and a booking modal work on a phone', async ({ page }) => {
        await submitLogin(page, USERS.standard);

        await expect(page.getByRole('heading', { level: 1, name: 'Mein Dashboard' })).toHaveCount(1);
        await page.getByRole('button', { name: /^Leasingrückgabe/ }).click();
        await expect(page.getByRole('dialog', { name: /Leasingrückgabe buchen/ })).toBeVisible();
    });
});
