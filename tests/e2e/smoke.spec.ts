import { expect, test } from '@playwright/test';
import { ADMIN, CUSTOMER, login, loginAs } from './helpers';

test.describe('access control', () => {
    test('a guest is redirected to login', async ({ page }) => {
        await page.goto('/dashboard');
        await expect(page).toHaveURL(/\/login/);
    });

    test('a guest cannot reach the admin area', async ({ page }) => {
        await page.goto('/admin/orders');
        await expect(page).toHaveURL(/\/login/);
    });

    test('a customer can log in and reach their dashboard', async ({ page }) => {
        await login(page, CUSTOMER);
        await expect(page).toHaveURL(/\/(dashboard|onboarding)/);
    });

    test('a customer is refused the admin area', async ({ page }) => {
        await loginAs(page, CUSTOMER);
        const response = await page.goto('/admin/orders');
        expect(response?.status()).toBe(403);
    });

    test('an admin can log in and reach the orders list', async ({ page }) => {
        await loginAs(page, ADMIN);
        await page.goto('/admin/orders');
        await expect(page).toHaveURL(/\/admin\/orders/);
        await expect(page.locator('body')).toContainText(/auftr|order/i);
    });

    test('an admin is not sent through customer onboarding', async ({ page }) => {
        await loginAs(page, ADMIN);
        await page.goto('/onboarding');
        await expect(page).not.toHaveURL(/\/onboarding/);
    });
});
