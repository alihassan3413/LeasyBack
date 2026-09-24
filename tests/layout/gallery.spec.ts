import { expect, test, type Page } from '@playwright/test';

const LABEL = 'Schadenbilder Position 1';

async function open(page: Page, count = 3) {
    await page.goto(`/gallery.html?count=${count}`);
    await page.waitForSelector('#wrap');
}

function thumbnails(page: Page) {
    return page.getByRole('list', { name: LABEL }).getByRole('button');
}

function lightbox(page: Page) {
    return page.getByRole('dialog');
}

async function noHorizontalScroll(page: Page): Promise<boolean> {
    return page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth);
}

test.describe('thumbnails', () => {
    test('one lazy, cropped thumbnail per image', async ({ page }) => {
        await open(page, 3);

        await expect(thumbnails(page)).toHaveCount(3);

        const image = thumbnails(page).first().locator('img');
        await expect(image).toHaveAttribute('loading', 'lazy');
        await expect(image).toHaveCSS('object-fit', 'cover');
        await expect(thumbnails(page).first()).toHaveCSS('border-radius', '13px');
    });

    test('each thumbnail carries a German label with its position and caption', async ({ page }) => {
        await open(page, 3);

        await expect(thumbnails(page).nth(0)).toHaveAccessibleName(`${LABEL}, Bild 1 von 3: Stoßfänger vorne links vergrößern`);
        await expect(thumbnails(page).nth(1)).toHaveAccessibleName(`${LABEL}, Bild 2 von 3 vergrößern`);
    });

    test('nothing renders without images', async ({ page }) => {
        await open(page, 0);

        await expect(page.getByTestId('damage-gallery')).toHaveCount(0);
    });

    test('many thumbnails wrap instead of scrolling sideways on a phone', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await open(page, 12);

        expect(await noHorizontalScroll(page)).toBe(true);

        const box = await thumbnails(page).first().boundingBox();
        expect(box?.width ?? 0).toBeGreaterThanOrEqual(44);
        expect(box?.height ?? 0).toBeGreaterThanOrEqual(44);
    });
});

test.describe('lightbox', () => {
    test('clicking a thumbnail opens that image uncropped', async ({ page }) => {
        await open(page, 3);

        await thumbnails(page).nth(1).click();

        await expect(lightbox(page)).toBeVisible();
        await expect(lightbox(page)).toHaveAccessibleName(LABEL);
        await expect(lightbox(page).getByText('Bild 2 von 3')).toBeVisible();

        const image = lightbox(page).locator('img');
        await expect(image).toHaveCSS('object-fit', 'contain');
        await expect(image).toHaveAttribute('alt', `${LABEL}, Bild 2 von 3`);
    });

    test('a caption is shown and used as the image text', async ({ page }) => {
        await open(page, 3);

        await thumbnails(page).first().click();

        await expect(lightbox(page).getByText('Stoßfänger vorne links', { exact: true })).toBeVisible();
        await expect(lightbox(page).locator('img')).toHaveAttribute('alt', 'Stoßfänger vorne links');
    });

    test('previous and next buttons step through and stop at the ends', async ({ page }) => {
        await open(page, 3);
        await thumbnails(page).first().click();

        const previous = lightbox(page).getByRole('button', { name: 'Vorheriges Bild' });
        const next = lightbox(page).getByRole('button', { name: 'Nächstes Bild' });

        await expect(previous).toBeDisabled();

        await next.click();
        await expect(lightbox(page).getByText('Bild 2 von 3')).toBeVisible();

        await next.click();
        await expect(lightbox(page).getByText('Bild 3 von 3')).toBeVisible();
        await expect(next).toBeDisabled();

        await previous.click();
        await expect(lightbox(page).getByText('Bild 2 von 3')).toBeVisible();
    });

    test('arrow keys, Home and End navigate', async ({ page }) => {
        await open(page, 4);
        await thumbnails(page).first().click();

        await page.keyboard.press('ArrowRight');
        await expect(lightbox(page).getByText('Bild 2 von 4')).toBeVisible();

        await page.keyboard.press('End');
        await expect(lightbox(page).getByText('Bild 4 von 4')).toBeVisible();

        await page.keyboard.press('ArrowRight');
        await expect(lightbox(page).getByText('Bild 4 von 4')).toBeVisible();

        await page.keyboard.press('Home');
        await expect(lightbox(page).getByText('Bild 1 von 4')).toBeVisible();

        await page.keyboard.press('ArrowLeft');
        await expect(lightbox(page).getByText('Bild 1 von 4')).toBeVisible();
    });

    test('the keyboard alone can open the gallery', async ({ page }) => {
        await open(page, 3);

        await page.locator('#before').focus();
        await page.keyboard.press('Tab');
        await expect(thumbnails(page).first()).toBeFocused();

        await page.keyboard.press('Enter');
        await expect(lightbox(page)).toBeVisible();
    });

    test('Escape closes and focus returns to the thumbnail last viewed', async ({ page }) => {
        await open(page, 3);
        await thumbnails(page).first().click();
        await page.keyboard.press('ArrowRight');

        await page.keyboard.press('Escape');

        await expect(lightbox(page)).toHaveCount(0);
        await expect(thumbnails(page).nth(1)).toBeFocused();
    });

    test('the close button is labelled in German and closes the lightbox', async ({ page }) => {
        await open(page, 3);
        await thumbnails(page).first().click();

        await lightbox(page).getByRole('button', { name: 'Schließen' }).click();

        await expect(lightbox(page)).toHaveCount(0);
        await expect(thumbnails(page).first()).toBeFocused();
    });

    test('arrow keys do nothing while the lightbox is closed', async ({ page }) => {
        await open(page, 3);
        await thumbnails(page).first().click();
        await page.keyboard.press('Escape');

        await page.keyboard.press('ArrowRight');
        await thumbnails(page).first().click();

        await expect(lightbox(page).getByText('Bild 1 von 3')).toBeVisible();
    });

    test('a single image has no navigation', async ({ page }) => {
        await open(page, 1);
        await thumbnails(page).first().click();

        await expect(lightbox(page).getByText('Bild 1 von 1')).toBeVisible();
        await expect(lightbox(page).getByRole('button', { name: 'Vorheriges Bild' })).toHaveCount(0);
        await expect(lightbox(page).getByRole('button', { name: 'Nächstes Bild' })).toHaveCount(0);
    });

    test('a horizontal swipe changes the image', async ({ page }) => {
        await open(page, 3);
        await thumbnails(page).first().click();

        const stage = await page.getByTestId('damage-gallery-stage').boundingBox();
        if (!stage) {
            throw new Error('stage not rendered');
        }

        const y = stage.y + stage.height * 0.25;
        const center = stage.x + stage.width / 2;

        await page.mouse.move(center + 120, y);
        await page.mouse.down();
        await page.mouse.move(center, y, { steps: 5 });
        await page.mouse.move(center - 120, y, { steps: 5 });
        await page.mouse.up();
        await expect(lightbox(page).getByText('Bild 2 von 3')).toBeVisible();

        await page.mouse.move(center - 120, y);
        await page.mouse.down();
        await page.mouse.move(center, y, { steps: 5 });
        await page.mouse.move(center + 120, y, { steps: 5 });
        await page.mouse.up();
        await expect(lightbox(page).getByText('Bild 1 von 3')).toBeVisible();
    });

    test('the lightbox fits a phone without sideways scrolling', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await open(page, 3);
        await thumbnails(page).first().click();

        expect(await noHorizontalScroll(page)).toBe(true);

        const image = await lightbox(page).locator('img').boundingBox();
        expect(image?.width ?? 0).toBeLessThanOrEqual(390);

        const next = await lightbox(page).getByRole('button', { name: 'Nächstes Bild' }).boundingBox();
        expect(next?.width ?? 0).toBeGreaterThanOrEqual(44);
        expect(next?.height ?? 0).toBeGreaterThanOrEqual(44);
    });
});

test.describe('reduced motion', () => {
    test('thumbnails and controls drop their transitions', async ({ page }) => {
        await page.emulateMedia({ reducedMotion: 'reduce' });
        await open(page, 3);

        await expect(thumbnails(page).first()).toHaveCSS('transition-property', 'none');

        await thumbnails(page).first().click();

        await expect(lightbox(page).getByRole('button', { name: 'Nächstes Bild' })).toHaveCSS('transition-property', 'none');
    });
});

test.describe('thumbnail variants', () => {
    test('the grid loads the small thumbnail and the lightbox the full image', async ({ page }) => {
        await open(page, 3);

        const gridImage = thumbnails(page).first().locator('img');
        await expect.poll(() => gridImage.evaluate((element: HTMLImageElement) => element.naturalWidth)).toBe(400);

        await thumbnails(page).first().click();

        const fullImage = lightbox(page).getByTestId('damage-gallery-image');
        await expect.poll(() => fullImage.evaluate((element: HTMLImageElement) => element.naturalWidth)).toBe(1600);
    });

    test('a skeleton holds the thumbnail slot until the image resolves', async ({ page }) => {
        await page.route('**/missing-thumbnail.png', async () => {});
        await page.goto('/gallery.html?count=2&broken=1');
        await page.waitForSelector('#wrap');

        await expect(thumbnails(page).first().getByTestId('damage-gallery-skeleton')).toBeVisible();
        await expect(thumbnails(page).nth(1).getByTestId('damage-gallery-skeleton')).toHaveCount(0);
    });
});

test.describe('zoom', () => {
    async function openZoomed(page: Page) {
        await open(page, 3);
        await thumbnails(page).first().click();
    }

    function zoomLevel(page: Page) {
        return lightbox(page).getByTestId('damage-gallery-zoom-level');
    }

    test('starts at 100% with zoom out and reset unavailable', async ({ page }) => {
        await openZoomed(page);

        await expect(zoomLevel(page)).toHaveText('100%');
        await expect(lightbox(page).getByRole('button', { name: 'Verkleinern' })).toBeDisabled();
        await expect(lightbox(page).getByRole('button', { name: 'Zoom zurücksetzen' })).toBeDisabled();
        await expect(lightbox(page).getByRole('button', { name: 'Vergrößern' })).toBeEnabled();
    });

    test('zooming in scales the image and zooming out reverses it', async ({ page }) => {
        await openZoomed(page);

        const image = lightbox(page).getByTestId('damage-gallery-image');

        await lightbox(page).getByRole('button', { name: 'Vergrößern' }).click();
        await expect(zoomLevel(page)).toHaveText('150%');
        await expect(image).toHaveCSS('transform', 'matrix(1.5, 0, 0, 1.5, 0, 0)');

        await lightbox(page).getByRole('button', { name: 'Verkleinern' }).click();
        await expect(zoomLevel(page)).toHaveText('100%');
        await expect(image).toHaveCSS('transform', 'matrix(1, 0, 0, 1, 0, 0)');
    });

    test('reset returns to 100% from any zoom level', async ({ page }) => {
        await openZoomed(page);

        await lightbox(page).getByRole('button', { name: 'Vergrößern' }).click();
        await lightbox(page).getByRole('button', { name: 'Vergrößern' }).click();
        await expect(zoomLevel(page)).toHaveText('200%');

        await lightbox(page).getByRole('button', { name: 'Zoom zurücksetzen' }).click();

        await expect(zoomLevel(page)).toHaveText('100%');
        await expect(lightbox(page).getByTestId('damage-gallery-image')).toHaveCSS('transform', 'matrix(1, 0, 0, 1, 0, 0)');
    });

    test('zoom stops at 400%', async ({ page }) => {
        await openZoomed(page);

        const zoomIn = lightbox(page).getByRole('button', { name: 'Vergrößern' });

        for (let step = 0; step < 10; step++) {
            if (await zoomIn.isEnabled()) {
                await zoomIn.click();
            }
        }

        await expect(zoomLevel(page)).toHaveText('400%');
        await expect(zoomIn).toBeDisabled();
    });

    test('the keyboard zooms in, out and resets', async ({ page }) => {
        await openZoomed(page);

        await page.keyboard.press('+');
        await expect(zoomLevel(page)).toHaveText('150%');

        await page.keyboard.press('-');
        await expect(zoomLevel(page)).toHaveText('100%');

        await page.keyboard.press('+');
        await page.keyboard.press('+');
        await expect(zoomLevel(page)).toHaveText('200%');

        await page.keyboard.press('0');
        await expect(zoomLevel(page)).toHaveText('100%');
    });

    test('moving to another image resets the zoom', async ({ page }) => {
        await openZoomed(page);

        await lightbox(page).getByRole('button', { name: 'Vergrößern' }).click();
        await expect(zoomLevel(page)).toHaveText('150%');

        await lightbox(page).getByRole('button', { name: 'Nächstes Bild' }).click();

        await expect(lightbox(page).getByText('Bild 2 von 3')).toBeVisible();
        await expect(zoomLevel(page)).toHaveText('100%');
    });

    test('a swipe pans instead of paging while zoomed', async ({ page }) => {
        await openZoomed(page);
        await lightbox(page).getByRole('button', { name: 'Vergrößern' }).click();

        const stage = await page.getByTestId('damage-gallery-stage').boundingBox();
        if (!stage) {
            throw new Error('stage not rendered');
        }

        const y = stage.y + stage.height / 2;
        const center = stage.x + stage.width / 2;

        await page.mouse.move(center + 120, y);
        await page.mouse.down();
        await page.mouse.move(center, y, { steps: 5 });
        await page.mouse.move(center - 120, y, { steps: 5 });
        await page.mouse.up();

        await expect(lightbox(page).getByText('Bild 1 von 3')).toBeVisible();
    });
});

test.describe('broken images', () => {
    test('a thumbnail that fails to load falls back to a placeholder', async ({ page }) => {
        await page.goto('/gallery.html?count=3&broken=1');
        await page.waitForSelector('#wrap');

        await expect(thumbnails(page).first().getByTestId('damage-gallery-fallback')).toBeVisible();
        await expect(thumbnails(page).first().locator('img')).toHaveCSS('opacity', '0');

        await expect(thumbnails(page).nth(1).getByTestId('damage-gallery-fallback')).toHaveCount(0);
        await expect(thumbnails(page).nth(1).locator('img')).toHaveCSS('opacity', '1');
    });

    test('the placeholder keeps the thumbnail clickable and labelled', async ({ page }) => {
        await page.goto('/gallery.html?count=3&broken=1');
        await page.waitForSelector('#wrap');

        await expect(thumbnails(page).first()).toHaveAccessibleName(`${LABEL}, Bild 1 von 3: Stoßfänger vorne links vergrößern`);

        await thumbnails(page).first().click();

        await expect(lightbox(page)).toBeVisible();
    });

    test('a full image that fails to load falls back inside the lightbox', async ({ page }) => {
        await page.goto('/gallery.html?count=3&broken=1');
        await page.waitForSelector('#wrap');

        await thumbnails(page).first().click();

        await expect(lightbox(page).getByTestId('damage-gallery-lightbox-fallback')).toBeVisible();
        await expect(lightbox(page).getByText('Bild nicht verfügbar')).toBeVisible();

        await lightbox(page).getByRole('button', { name: 'Nächstes Bild' }).click();

        await expect(lightbox(page).getByTestId('damage-gallery-lightbox-fallback')).toHaveCount(0);
        await expect(lightbox(page).getByTestId('damage-gallery-image')).toBeVisible();
    });
});
