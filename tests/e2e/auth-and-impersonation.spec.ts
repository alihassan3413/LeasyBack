import { expect, loginAs, submitLogin, test, USERS } from './fixtures';

test.describe('sign-in and MFA', () => {
    test('a company owner passes the authenticator challenge and reaches the dashboard', async ({ page }) => {
        await loginAs(page, USERS.owner);

        await expect(page).toHaveURL(/\/dashboard/);
    });

    test('a standard member is not asked for a second factor', async ({ page }) => {
        await submitLogin(page, USERS.standard);

        await expect(page).toHaveURL(/\/dashboard/);
    });

    test('an owner who never enrolled is pinned to MFA setup on a direct login', async ({ page }) => {
        await submitLogin(page, USERS.target);

        await expect(page).toHaveURL(/\/mfa\/setup/);
        await page.goto('/fahrzeuge');
        await expect(page).toHaveURL(/\/mfa\/setup/);
    });
});

test.describe('admin impersonation', () => {
    test('the admin takes over an unenrolled owner without MFA, then returns', async ({ page }) => {
        await loginAs(page, USERS.admin);
        await expect(page).toHaveURL(/\/admin/);

        await page.goto('/admin/customers?type=b2b');
        await page.getByPlaceholder('Name, E-Mail, Stadt…').fill('Übernahme');
        const row = page.getByRole('row', { name: /E2E Übernahme GmbH/ });
        await expect(row).toHaveCount(1);
        await row.getByRole('button', { name: 'Als dieser Kunde anmelden' }).click();

        // The customer's own MFA (not enrolled, owner → must enrol) is not asked for.
        await expect(page).toHaveURL(/\/dashboard/);
        await page.goto('/fahrzeuge');
        await expect(page).toHaveURL(/\/fahrzeuge/);

        await page.getByRole('button', { name: 'Beenden' }).click();
        await expect(page).toHaveURL(/\/admin\/customers/);

        // The way back restored the admin; the customer's rules are untouched.
        await page.goto('/admin/dashboard');
        await expect(page).toHaveURL(/\/admin\/dashboard/);
    });
});
