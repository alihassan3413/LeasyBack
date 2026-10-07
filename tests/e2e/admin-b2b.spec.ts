import { BUSY_PLATE, COMPANY, expect, loginAs, submitLogin, test, USERS } from './fixtures';

test.describe('admin managing B2B', () => {
    test('opens the B2B company, its vehicles and an order without errors', async ({ page }) => {
        await loginAs(page, USERS.admin2);

        await page.goto('/admin/customers?type=b2b');
        await page.getByPlaceholder('Name, E-Mail, Stadt…').fill('Fleet');
        // One row per company user (owner, standard, read-only).
        await expect(page.getByRole('row', { name: new RegExp(COMPANY) }).first()).toBeVisible();

        await page.goto('/admin/vehicles');
        await expect(page.getByText(BUSY_PLATE).filter({ visible: true }).first()).toBeVisible();

        await page.goto('/admin/orders');
        await page.getByText(BUSY_PLATE).filter({ visible: true }).first().click();
        await expect(page).toHaveURL(/\/admin\/orders\/[0-9a-f-]{36}/);
        await expect(page.getByText(BUSY_PLATE).filter({ visible: true }).first()).toBeVisible();

        for (const path of ['/admin/dashboard', '/admin/statistics/gutachten', '/admin/statistics/unfallschaden']) {
            await page.goto(path);
            await expect(page).toHaveURL(new RegExp(path));
        }
    });
});

test('the notifications panel opens for a company member', async ({ page }) => {
    await submitLogin(page, USERS.standard);

    await page.getByRole('button', { name: 'Benachrichtigungen' }).click();
    await expect(page.getByRole('dialog').or(page.getByRole('menu')).first()).toBeVisible();
});
