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
