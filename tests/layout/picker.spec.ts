import { expect, test, type Page } from '@playwright/test';

const LABEL = 'Schadenbilder Position 1';

async function open(page: Page, params: Record<string, string> = {}) {
    await page.goto(`/picker.html?${new URLSearchParams(params).toString()}`);
    await page.waitForSelector('[data-testid="damage-image-picker"]');
}

function selectButton(page: Page, name: string) {
    return page.getByRole('list', { name: LABEL }).getByRole('button', { name, exact: true });
}

function previewButton(page: Page, name: string) {
    return page.getByRole('list', { name: LABEL }).getByRole('button', { name: `${name} vergrößern`, exact: true });
}

async function model(page: Page): Promise<string[]> {
    return JSON.parse((await page.locator('#model').textContent()) ?? '[]');
}

test.describe('thumbnails', () => {
    test('only image documents are offered', async ({ page }) => {
        await open(page, { images: '3' });

        const list = page.getByRole('list', { name: LABEL });

        await expect(list.getByRole('listitem')).toHaveCount(3);
        await expect(selectButton(page, 'Bild 1: Stoßfänger vorne')).toBeVisible();
        await expect(selectButton(page, 'Bild 2')).toBeVisible();
        await expect(page.getByText('Erstgutachten')).toHaveCount(0);
    });

    test('thumbnails are lazy, cropped and use the admin image route', async ({ page }) => {
        await open(page, { images: '2' });

        const image = selectButton(page, 'Bild 2').locator('img');

        await expect(image).toHaveAttribute('loading', 'lazy');
        await expect(image).toHaveCSS('object-fit', 'cover');
        await expect(image).toHaveAttribute('src', /^data:image\/svg\+xml/);
        await expect(selectButton(page, 'Bild 2')).toHaveCSS('border-radius', '13px');
    });

    test('an order without images says so in one sentence', async ({ page }) => {
        await open(page, { images: '0' });

        await expect(page.getByText('Für diesen Auftrag sind noch keine Bilder hochgeladen.')).toBeVisible();
        await expect(page.getByRole('list', { name: LABEL })).toHaveCount(0);
    });

    test('select and preview targets are at least 44px', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await open(page, { images: '6' });

        for (const button of [selectButton(page, 'Bild 2'), previewButton(page, 'Bild 2')]) {
            const box = await button.boundingBox();
            expect(box?.width ?? 0).toBeGreaterThanOrEqual(44);
            expect(box?.height ?? 0).toBeGreaterThanOrEqual(44);
        }

        expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
    });
});

test.describe('selection', () => {
    test('existing selections arrive through v-model', async ({ page }) => {
        await open(page, { images: '3', selected: 'image-2' });

        await expect(selectButton(page, 'Bild 2')).toHaveAttribute('aria-pressed', 'true');
        await expect(selectButton(page, 'Bild 1: Stoßfänger vorne')).toHaveAttribute('aria-pressed', 'false');
        await expect(page.getByText(`${LABEL}: 1 von 3 ausgewählt`)).toBeVisible();
    });

    test('clicking toggles and keeps document ids in selection order', async ({ page }) => {
        await open(page, { images: '3' });

        await selectButton(page, 'Bild 3').click();
        await selectButton(page, 'Bild 1: Stoßfänger vorne').click();
        expect(await model(page)).toEqual(['image-3', 'image-1']);
        await expect(selectButton(page, 'Bild 3')).toHaveAttribute('aria-pressed', 'true');

        await selectButton(page, 'Bild 3').click();
        expect(await model(page)).toEqual(['image-1']);
        await expect(selectButton(page, 'Bild 3')).toHaveAttribute('aria-pressed', 'false');
    });

    test('a selected tile shows the check indicator', async ({ page }) => {
        await open(page, { images: '2', selected: 'image-1' });

        const selectedCheck = selectButton(page, 'Bild 1: Stoßfänger vorne').getByTestId('damage-image-check');
        const unselectedCheck = selectButton(page, 'Bild 2').getByTestId('damage-image-check');

        await expect(selectedCheck).toHaveCSS('background-color', 'rgb(1, 185, 144)');
        await expect(unselectedCheck).not.toHaveCSS('background-color', 'rgb(1, 185, 144)');
        await expect(selectButton(page, 'Bild 1: Stoßfänger vorne')).toHaveCSS('border-color', 'rgb(1, 185, 144)');
    });

    test('the keyboard can select with Space and Enter', async ({ page }) => {
        await open(page, { images: '3' });

        await selectButton(page, 'Bild 1: Stoßfänger vorne').focus();
        await page.keyboard.press('Space');
        expect(await model(page)).toEqual(['image-1']);

        await page.keyboard.press('Tab');
        await expect(previewButton(page, 'Bild 1: Stoßfänger vorne')).toBeFocused();

        await page.keyboard.press('Tab');
        await expect(selectButton(page, 'Bild 2')).toBeFocused();
        await page.keyboard.press('Enter');
        expect(await model(page)).toEqual(['image-1', 'image-2']);
    });

    test('a disabled picker keeps its selection and still previews', async ({ page }) => {
        await open(page, { images: '2', selected: 'image-1', disabled: '1' });

        await expect(selectButton(page, 'Bild 2')).toBeDisabled();
        await selectButton(page, 'Bild 2').click({ force: true });
        expect(await model(page)).toEqual(['image-1']);

        await previewButton(page, 'Bild 2').click();
        await expect(page.getByRole('dialog')).toBeVisible();
    });
});

test.describe('non-image links', () => {
    test('an already linked non-image document is kept and flagged', async ({ page }) => {
        await open(page, { images: '2', selected: 'report-pdf' });

        await expect(page.getByText('1 verknüpftes Dokument ist kein Bild und wird Werkstätten nicht angezeigt.')).toBeVisible();

        await selectButton(page, 'Bild 2').click();
        expect(await model(page)).toEqual(['report-pdf', 'image-2']);
    });

    test('the non-image link can be removed without touching image selections', async ({ page }) => {
        await open(page, { images: '2', selected: 'report-pdf,image-1' });

        await page.getByRole('button', { name: 'Verknüpfung entfernen' }).click();

        expect(await model(page)).toEqual(['image-1']);
        await expect(page.getByText('verknüpftes Dokument ist kein Bild', { exact: false })).toHaveCount(0);
    });

    test('a disabled picker offers no removal', async ({ page }) => {
        await open(page, { images: '1', selected: 'report-pdf', disabled: '1' });

        await expect(page.getByRole('button', { name: 'Verknüpfung entfernen' })).toHaveCount(0);
    });
});

test.describe('preview', () => {
    test('the preview opens the lightbox on that image without changing the selection', async ({ page }) => {
        await open(page, { images: '3' });

        await previewButton(page, 'Bild 2').click();

        const dialog = page.getByRole('dialog');
        await expect(dialog).toBeVisible();
        await expect(dialog).toHaveAccessibleName(LABEL);
        await expect(dialog.getByText('Bild 2 von 3')).toBeVisible();
        await expect(dialog.locator('img')).toHaveCSS('object-fit', 'contain');
        expect(await model(page)).toEqual([]);
    });

    test('closing returns focus to the preview button of the image last viewed', async ({ page }) => {
        await open(page, { images: '3' });

        await previewButton(page, 'Bild 1: Stoßfänger vorne').click();
        await page.keyboard.press('ArrowRight');
        await page.keyboard.press('Escape');

        await expect(page.getByRole('dialog')).toHaveCount(0);
        await expect(previewButton(page, 'Bild 2')).toBeFocused();
    });
});

test.describe('thumbnail variants', () => {
    test('the grid loads the derived thumbnail, not the full image', async ({ page }) => {
        await open(page, { images: '2' });

        const image = selectButton(page, 'Bild 2').locator('img');

        await expect.poll(() => image.evaluate((element: HTMLImageElement) => element.naturalWidth)).toBe(400);
    });

    test('the preview lightbox still opens the full image', async ({ page }) => {
        await open(page, { images: '2' });

        await page
            .getByRole('button', { name: /vergrößern$/ })
            .first()
            .click();

        const full = page.getByRole('dialog').getByTestId('damage-gallery-image');
        await expect.poll(() => full.evaluate((element: HTMLImageElement) => element.naturalWidth)).toBe(1200);
    });

    test('a thumbnail that fails to load falls back to a placeholder', async ({ page }) => {
        await page.route('**/*.png', (route) => route.abort());
        await open(page, { images: '2' });

        await page.evaluate(() => {
            for (const image of Array.from(document.querySelectorAll('[data-testid="damage-image-picker"] img'))) {
                (image as HTMLImageElement).src = '/missing-thumbnail.png';
            }
        });

        await expect(page.getByTestId('damage-image-fallback').first()).toBeVisible();
    });
});
