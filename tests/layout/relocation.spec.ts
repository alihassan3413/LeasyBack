import { test } from '@playwright/test';

test('capture relocation modal', async ({ page }) => {
    await page.setViewportSize({ width: 820, height: 1250 });
    await page.goto('/relocation.html');
    await page.getByText('Abholadresse').first().waitFor();
    await page.waitForTimeout(400);
    await page.screenshot({ path: 'tests/layout/screenshots/relocation.png' });
});
