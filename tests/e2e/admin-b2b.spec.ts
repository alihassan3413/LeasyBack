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

test('saving the pickup disables the button until something changes again', async ({ page }) => {
    await loginAs(page, USERS.admin2);
    await page.goto('/admin/orders');
    await page.getByText(BUSY_PLATE).filter({ visible: true }).first().click();
    await expect(page).toHaveURL(/\/admin\/orders\/[0-9a-f-]{36}/);

    const save = page.getByRole('button', { name: 'Abholung speichern' });
    const note = page.getByPlaceholder('Nur für Leasyback sichtbar...');
    const patches: string[] = [];
    page.on('request', (request) => request.method() === 'PATCH' && /\/collection/.test(request.url()) && patches.push(request.url()));

    // Nothing edited yet: nothing to save.
    await expect(save).toBeDisabled();

    await note.fill('Schlüssel liegt am Empfang');
    await expect(save).toBeEnabled();

    const saved = page.waitForResponse((response) => response.request().method() === 'PATCH' && /\/collection/.test(response.url()));
    await save.click();
    expect((await saved).status()).toBeLessThan(400);

    // Saved values are the new baseline: the same PATCH cannot be sent twice.
    await expect(save).toBeDisabled();
    await expect(note).toHaveValue('Schlüssel liegt am Empfang');
    expect(patches).toHaveLength(1);

    // A further edit makes it savable again.
    await note.fill('Schlüssel liegt beim Pförtner');
    await expect(save).toBeEnabled();
});
