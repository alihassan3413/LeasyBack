import { expect, test } from '@playwright/test';

test('capture relocation modal', async ({ page }) => {
    await page.setViewportSize({ width: 820, height: 1250 });
    await page.goto('/relocation.html');
    await page.getByText('Abholadresse').first().waitFor();
    await page.waitForTimeout(400);
    await page.screenshot({ path: 'tests/layout/screenshots/relocation.png' });
});

test.describe('phone masking', () => {
    test('a German number is grouped as it is typed', async ({ page }) => {
        await page.goto('/relocation.html');
        await page.getByText('Abholadresse').first().waitFor();

        const phone = page.getByPlaceholder('z. B. 030 12345678').first();
        await phone.pressSequentially('030123456 78');

        // 030 is a two-digit area code; 0221 is three and 08031 is four, which
        // is why a fixed mask cannot do this.
        await expect(phone).toHaveValue('030 12345678');
    });

    test('a different area-code length is grouped differently', async ({ page }) => {
        await page.goto('/relocation.html');
        await page.getByText('Abholadresse').first().waitFor();

        const phone = page.getByPlaceholder('z. B. 030 12345678').first();
        await phone.pressSequentially('08031 1234');

        await expect(phone).toHaveValue('08031 1234');
    });

    test('letters and punctuation are dropped', async ({ page }) => {
        await page.goto('/relocation.html');
        await page.getByText('Abholadresse').first().waitFor();

        const phone = page.getByPlaceholder('z. B. 030 12345678').first();
        await phone.pressSequentially('(030) abc 123-456');

        await expect(phone).toHaveValue('030 123456');
    });
});
