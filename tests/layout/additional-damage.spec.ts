import { expect, test, type Page } from '@playwright/test';

async function open(page: Page, query = '') {
    await page.goto(`/additional-damage.html${query}`);
    await page.waitForSelector('#wrap');
}

function cards(page: Page) {
    return page.getByTestId('additional-damage-card');
}

/** A 1x1 PNG, so the browser produces a real object URL for the preview. */
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');

async function attach(page: Page, cardIndex: number, names: string[]) {
    await cards(page)
        .nth(cardIndex)
        .getByTestId('additional-damage-image-input')
        .setInputFiles(names.map((name) => ({ name, mimeType: 'image/png', buffer: PNG })));
}

test.describe('adding and removing', () => {
    test('no card is shown until one is added', async ({ page }) => {
        await open(page);

        await expect(cards(page)).toHaveCount(0);
        await expect(page.getByTestId('additional-damage-add')).toBeVisible();
    });

    test('the add button creates a card and several can coexist', async ({ page }) => {
        await open(page);

        await page.getByTestId('additional-damage-add').click();
        await expect(cards(page)).toHaveCount(1);

        await page.getByTestId('additional-damage-add').click();
        await page.getByTestId('additional-damage-add').click();
        await expect(cards(page)).toHaveCount(3);

        await expect(cards(page).nth(0)).toContainText('Zusätzlicher Schaden 1');
        await expect(cards(page).nth(2)).toContainText('Zusätzlicher Schaden 3');
    });

    test('a card can be removed again', async ({ page }) => {
        await open(page, '?positions=2');

        await cards(page).first().getByTestId('additional-damage-remove').click();

        await expect(cards(page)).toHaveCount(1);
    });

    test('the card is visibly marked as workshop-reported, not from the Gutachten', async ({ page }) => {
        await open(page, '?positions=1');

        await expect(cards(page).first()).toContainText('Zusätzlicher Schaden 1');
        await expect(cards(page).first()).toHaveCSS('border-style', 'dashed');
    });
});

test.describe('images', () => {
    test('attaching files renders a preview each and counts them', async ({ page }) => {
        await open(page, '?positions=1');

        await attach(page, 0, ['a.png', 'b.png']);

        await expect(cards(page).first().getByTestId('additional-damage-preview')).toHaveCount(2);
        await expect(cards(page).first()).toContainText('2 von 5');
    });

    test('a preview can be removed', async ({ page }) => {
        await open(page, '?positions=1');
        await attach(page, 0, ['a.png', 'b.png']);

        await cards(page).first().getByTestId('additional-damage-image-remove').first().click();

        await expect(cards(page).first().getByTestId('additional-damage-preview')).toHaveCount(1);
        await expect(cards(page).first()).toContainText('1 von 5');
    });

    test('the add-image control disappears once the limit is reached', async ({ page }) => {
        await open(page, '?positions=1&maxImages=2');

        await attach(page, 0, ['a.png', 'b.png']);

        await expect(cards(page).first().getByTestId('additional-damage-preview')).toHaveCount(2);
        await expect(cards(page).first().getByTestId('additional-damage-image-input')).toHaveCount(0);
    });

    test('more files than the limit are ignored rather than accepted', async ({ page }) => {
        await open(page, '?positions=1&maxImages=2');

        await attach(page, 0, ['a.png', 'b.png', 'c.png', 'd.png']);

        await expect(cards(page).first().getByTestId('additional-damage-preview')).toHaveCount(2);
    });

    /**
     * Regression: object-URL ownership. The cards are keyed by index, so
     * removing the first of two unmounts the *last* component while the
     * survivor's data slides down into the kept one — a revoke tied to unmount
     * therefore kills the survivor's preview.
     *
     * Asserted by fetching the URL rather than by looking at the image: a
     * revoked URL leaves an already-decoded <img> rendering happily, so the
     * damage is invisible on screen and only surfaces on the next fetch.
     */
    test("removing a card leaves the surviving card's preview URL alive", async ({ page }) => {
        await open(page, '?positions=2');
        await attach(page, 0, ['a.png']);
        await attach(page, 1, ['b.png']);

        const survivorSrc = await cards(page).nth(1).getByTestId('additional-damage-preview').getAttribute('src');

        await cards(page).first().getByTestId('additional-damage-remove').click();
        await expect(cards(page)).toHaveCount(1);

        const stillFetchable = await page.evaluate(async (src) => {
            try {
                return (await fetch(src!)).ok;
            } catch {
                return false;
            }
        }, survivorSrc);

        expect(stillFetchable).toBe(true);
    });

    test('an image removed by its own button has its URL revoked', async ({ page }) => {
        await open(page, '?positions=1');
        await attach(page, 0, ['a.png', 'b.png']);

        const doomed = await cards(page).first().getByTestId('additional-damage-preview').first().getAttribute('src');

        await cards(page).first().getByTestId('additional-damage-image-remove').first().click();
        await expect(cards(page).first().getByTestId('additional-damage-preview')).toHaveCount(1);

        const leaked = await page.evaluate(async (src) => {
            try {
                return (await fetch(src!)).ok;
            } catch {
                return false;
            }
        }, doomed);

        expect(leaked).toBe(false);
    });

    test('each card keeps its own images', async ({ page }) => {
        await open(page, '?positions=2');

        await attach(page, 0, ['a.png']);
        await attach(page, 1, ['b.png', 'c.png']);

        await expect(cards(page).nth(0).getByTestId('additional-damage-preview')).toHaveCount(1);
        await expect(cards(page).nth(1).getByTestId('additional-damage-preview')).toHaveCount(2);
    });
});

test.describe('the shared image allowance', () => {
    /**
     * PHP's max_file_uploads silently discards files past its limit, so the
     * server caps the whole submission. The picker spends the same allowance,
     * so a workshop is stopped here rather than after uploading photos that
     * were never going to be accepted.
     */
    test('images used on one card are taken off what another card is offered', async ({ page }) => {
        await open(page, '?positions=2&maxImages=5&maxImagesTotal=3');

        await attach(page, 0, ['a.png', 'b.png']);

        // A card's own images do not eat its own allowance, so card 1 is still
        // offered all three; card 2 sees only the one that is left.
        await expect(cards(page).nth(0)).toContainText('2 von 3');
        await expect(cards(page).nth(1)).toContainText('0 von 1');

        await attach(page, 1, ['c.png']);

        await expect(cards(page).nth(1).getByTestId('additional-damage-preview')).toHaveCount(1);
        await expect(cards(page).nth(0).getByTestId('additional-damage-image-input')).toHaveCount(0);
        await expect(cards(page).nth(1).getByTestId('additional-damage-image-input')).toHaveCount(0);
    });

    test('freeing an image gives the allowance back to the other card', async ({ page }) => {
        await open(page, '?positions=2&maxImages=5&maxImagesTotal=3');
        await attach(page, 0, ['a.png', 'b.png', 'c.png']);

        await expect(cards(page).nth(1).getByTestId('additional-damage-image-input')).toHaveCount(0);

        await cards(page).nth(0).getByTestId('additional-damage-image-remove').first().click();

        await expect(cards(page).nth(1).getByTestId('additional-damage-image-input')).toHaveCount(1);
    });
});

test.describe('validation', () => {
    test('server errors are shown against the field they belong to', async ({ page }) => {
        await open(page, '?positions=1&errors=1');

        await expect(cards(page).first()).toContainText('Bauteil muss ausgefüllt werden.');
        await expect(cards(page).first()).toContainText('Schadenbeschreibung muss ausgefüllt werden.');
        await expect(cards(page).first()).toContainText('Nettopreis muss ausgefüllt werden.');
    });
});

test.describe('locked state', () => {
    test('everything is disabled while the form is submitting', async ({ page }) => {
        await open(page, '?positions=1&disabled=1');

        await expect(page.getByTestId('additional-damage-add')).toBeDisabled();
        await expect(cards(page).first().getByTestId('additional-damage-remove')).toBeDisabled();
        await expect(cards(page).first().getByTestId('additional-damage-image-input')).toBeDisabled();
        await expect(cards(page).first().getByRole('textbox').first()).toBeDisabled();
    });
});

test.describe('accessibility and mobile', () => {
    test('controls carry names and the file input is reachable by label', async ({ page }) => {
        await open(page, '?positions=1');
        await attach(page, 0, ['a.png']);

        await expect(cards(page).first().getByRole('button', { name: 'Bild 1 entfernen' })).toBeVisible();
        await expect(cards(page).first().getByText('Bild hinzufügen')).toBeAttached();
        await expect(cards(page).first().getByTestId('additional-damage-preview')).toHaveAttribute('alt', /Bild 1 zu/);
    });

    test('the card fits a 390px phone without sideways scrolling', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await open(page, '?positions=2');
        await attach(page, 0, ['a.png', 'b.png', 'c.png']);

        expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);

        const remove = await cards(page).first().getByTestId('additional-damage-remove').boundingBox();
        expect(remove?.height ?? 0).toBeGreaterThanOrEqual(24);
    });
});
