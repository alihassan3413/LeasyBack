import { expect, test, type Page } from '@playwright/test';

const PDF_URL = '/werkstatt/angebot/tokentokentoken/pdf';

async function open(page: Page, query = '') {
    await page.goto(`/document-actions.html${query}`);
    await page.waitForSelector('#wrap');
}

const download = (page: Page) => page.getByTestId('quotation-pdf-download');
const print = (page: Page) => page.getByTestId('quotation-pdf-print');

test.describe('the document actions', () => {
    test('both actions are offered', async ({ page }) => {
        await open(page);

        await expect(page.getByTestId('quotation-document-actions')).toBeVisible();
        await expect(download(page)).toBeVisible();
        await expect(print(page)).toBeVisible();
    });

    test('the download action asks the server for an attachment', async ({ page }) => {
        await open(page);

        await expect(download(page)).toHaveAttribute('href', `${PDF_URL}?download=1`);
        await expect(download(page)).toContainText('PDF herunterladen');
    });

    /**
     * Printing deliberately opens the same server-rendered document in a new
     * tab rather than rendering a second print-only page in the browser.
     */
    test('the print action opens the same document in a new tab', async ({ page }) => {
        await open(page);

        await expect(print(page)).toHaveAttribute('href', PDF_URL);
        await expect(print(page)).toHaveAttribute('target', '_blank');
        await expect(print(page)).toHaveAttribute('rel', 'noopener');
        await expect(print(page)).toContainText('Drucken');
    });

    test('both actions point at the same document', async ({ page }) => {
        await open(page, '?pdfUrl=/werkstatt/angebot/andererToken/pdf');

        await expect(download(page)).toHaveAttribute('href', '/werkstatt/angebot/andererToken/pdf?download=1');
        await expect(print(page)).toHaveAttribute('href', '/werkstatt/angebot/andererToken/pdf');
    });
});

test.describe('accessibility', () => {
    test('each action has an accessible name', async ({ page }) => {
        await open(page);

        await expect(page.getByRole('link', { name: 'PDF herunterladen' })).toBeVisible();
        await expect(page.getByRole('link', { name: /Drucken/ })).toBeVisible();
    });

    /** The icons are decorative; the text carries the meaning. */
    test('the icons are hidden from assistive technology', async ({ page }) => {
        await open(page);

        for (const svg of await page.locator('[data-testid="quotation-document-actions"] svg').all()) {
            await expect(svg).toHaveAttribute('aria-hidden', 'true');
        }
    });

    test('the new tab is announced to screen readers', async ({ page }) => {
        await open(page);

        await expect(print(page)).toContainText('öffnet das PDF in einem neuen Tab');
    });
});

test.describe('mobile', () => {
    test('both actions keep a 44px target and fit a 390px phone', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await open(page);

        for (const action of [download(page), print(page)]) {
            const box = await action.boundingBox();
            expect(box?.height ?? 0).toBeGreaterThanOrEqual(44);
        }

        expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
    });

    test('the actions wrap instead of overflowing on a narrow phone', async ({ page }) => {
        await page.setViewportSize({ width: 320, height: 720 });
        await open(page);

        const first = await download(page).boundingBox();
        const second = await print(page).boundingBox();

        // Wrapped, so the second sits below the first rather than beside it.
        expect(second?.y ?? 0).toBeGreaterThan(first?.y ?? 0);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
    });
});
