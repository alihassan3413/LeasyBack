import { expect, test, type Page } from '@playwright/test';

async function open(page: Page, params: Record<string, string> = {}) {
    await page.goto(`/positions.html?${new URLSearchParams(params).toString()}`);
    await page.waitForSelector('.content-card');
}

function picker(page: Page, position: number) {
    return page.getByRole('list', { name: `Schadenbilder Position ${position}` });
}

function saveButton(page: Page) {
    return page.getByRole('button', { name: 'Positionen speichern' });
}

test.describe('damage images in the positions card', () => {
    test('a position with linked images opens with the thumbnail picker instead of checkboxes', async ({ page }) => {
        await open(page);

        await expect(picker(page, 1)).toBeVisible();
        await expect(picker(page, 1).getByRole('listitem')).toHaveCount(3);
        await expect(page.getByText('Schadenbilder Position 1: 1 von 3 ausgewählt')).toBeVisible();
        await expect(picker(page, 1).getByRole('button', { name: 'Bild 1: Stoßfänger vorne', exact: true })).toHaveAttribute('aria-pressed', 'true');
        await expect(page.locator('input[type="checkbox"]')).toHaveCount(0);
    });

    test('a linked non-image document stays visible as a notice', async ({ page }) => {
        await open(page);

        await expect(page.getByText('1 verknüpftes Dokument ist kein Bild und wird Werkstätten nicht angezeigt.')).toBeVisible();
    });

    test('selecting an image makes the form dirty and unselecting it makes it clean again', async ({ page }) => {
        await open(page);

        await expect(saveButton(page)).toBeDisabled();

        const second = picker(page, 1).getByRole('button', { name: 'Bild 2', exact: true });

        await second.click();
        await expect(second).toHaveAttribute('aria-pressed', 'true');
        await expect(page.getByText('Schadenbilder Position 1: 2 von 3 ausgewählt')).toBeVisible();
        await expect(saveButton(page)).toBeEnabled();

        await second.click();
        await expect(saveButton(page)).toBeDisabled();
    });

    test('removing the non-image link is a normal edit of the position', async ({ page }) => {
        await open(page);

        await page.getByRole('button', { name: 'Verknüpfung entfernen' }).click();

        await expect(page.getByText('verknüpftes Dokument ist kein Bild', { exact: false })).toHaveCount(0);
        await expect(saveButton(page)).toBeEnabled();
    });

    test('a position without images opens to an empty picker', async ({ page }) => {
        await open(page);

        await expect(picker(page, 2)).toHaveCount(0);

        await page.getByRole('button', { name: 'Beschreibung und Schadenbilder', exact: true }).click();

        await expect(picker(page, 2)).toBeVisible();
        await expect(page.getByText('Schadenbilder Position 2: 0 von 3 ausgewählt')).toBeVisible();
    });

    test('the collapsed detail toggle says how many images are linked', async ({ page }) => {
        await open(page);

        const toggle = page.getByRole('button', { name: 'Beschreibung und Schadenbilder (1 Bild verknüpft)' });

        await expect(toggle).toBeVisible();
        await toggle.click();
        await expect(picker(page, 1)).toHaveCount(0);

        await page.getByRole('button', { name: 'Beschreibung und Schadenbilder (1 Bild verknüpft)' }).click();
        await picker(page, 1).getByRole('button', { name: 'Bild 3', exact: true }).click();

        await expect(page.getByRole('button', { name: 'Beschreibung und Schadenbilder (2 Bilder verknüpft)' })).toBeVisible();
    });

    test('images can be previewed from inside the card', async ({ page }) => {
        await open(page);

        await picker(page, 1).getByRole('button', { name: 'Bild 2 vergrößern' }).click();

        await expect(page.getByRole('dialog', { name: 'Schadenbilder Position 1' })).toBeVisible();
        await expect(page.getByRole('dialog').getByText('Bild 2 von 3')).toBeVisible();

        await page.keyboard.press('Escape');
        await expect(page.getByRole('dialog')).toHaveCount(0);
        await expect(saveButton(page)).toBeDisabled();
    });

    test('a locked order shows the selection read-only', async ({ page }) => {
        await open(page, { editable: '0' });

        await expect(picker(page, 1).getByRole('button', { name: 'Bild 2', exact: true })).toBeDisabled();
        await expect(page.getByRole('button', { name: 'Verknüpfung entfernen' })).toHaveCount(0);
        await expect(saveButton(page)).toHaveCount(0);

        await picker(page, 1).getByRole('button', { name: 'Bild 2 vergrößern' }).click();
        await expect(page.getByRole('dialog')).toBeVisible();
    });

    test('the open detail row and its picker fit a phone', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await open(page);

        await expect(picker(page, 1)).toBeVisible();

        const overflowing = await page.evaluate(() => {
            const viewport = document.documentElement.clientWidth;
            const list = document.querySelector('[data-testid="damage-image-picker"]');
            const row = list?.closest('.grid');

            return [list, row, ...Array.from(list?.querySelectorAll('li, button') ?? [])].filter(
                (element) => element instanceof HTMLElement && element.getBoundingClientRect().right > viewport + 0.5,
            ).length;
        });

        expect(overflowing).toBe(0);
    });

    test('on wide screens the picker gets more room than the description', async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await open(page);

        const description = await page.getByPlaceholder('Optional').boundingBox();
        const images = await picker(page, 1).boundingBox();

        expect(images?.width ?? 0).toBeGreaterThan(description?.width ?? 0);
        expect(Math.abs((images?.y ?? 0) - (description?.y ?? 0))).toBeLessThan(80);
    });
});
