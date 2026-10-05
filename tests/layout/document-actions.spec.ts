import { expect, test, type Page, type Request } from '@playwright/test';

const PDF_URL = '/werkstatt/angebot/tokentokentoken/pdf';

/** The smallest body a browser accepts as a PDF; its content is never inspected. */
const FAKE_PDF = '%PDF-1.4\n%%EOF\n';

async function open(page: Page, query = '') {
    await page.goto(`/document-actions.html${query}`);
    await page.waitForSelector('#wrap');
}

/** Answers the PDF endpoint and records every request made to it. */
async function stubPdf(page: Page, status = 200): Promise<Request[]> {
    const requests: Request[] = [];

    // Matched on the path: the fixture page's own query string names the PDF URL too.
    await page.route(
        (url) => url.pathname.endsWith('/pdf'),
        async (route) => {
            requests.push(route.request());

            await route.fulfill(
                status === 200
                    ? {
                          status,
                          contentType: 'application/pdf',
                          headers: { 'Content-Disposition': 'attachment; filename="LeasyBack-Werkstattangebot-AUF-1.pdf"' },
                          body: FAKE_PDF,
                      }
                    : { status, contentType: 'application/json', body: JSON.stringify({ message: 'invalid' }) },
            );
        },
    );

    return requests;
}

const download = (page: Page) => page.getByTestId('quotation-pdf-download');
const print = (page: Page) => page.getByTestId('quotation-pdf-print');

test.describe('the document actions', () => {
    test('both actions are offered', async ({ page }) => {
        await open(page);

        await expect(page.getByTestId('quotation-document-actions')).toBeVisible();
        await expect(download(page)).toContainText('PDF herunterladen');
        await expect(print(page)).toContainText('Drucken');
    });

    /** The point of the change: prices typed but not sent still reach the PDF. */
    test('the download posts the unsent form values and saves the returned file', async ({ page }) => {
        const requests = await stubPdf(page);
        await open(page);

        const saved = page.waitForEvent('download');
        await download(page).click();

        expect((await saved).suggestedFilename()).toBe('LeasyBack-Werkstattangebot-AUF-1.pdf');
        expect(requests).toHaveLength(1);
        expect(requests[0].method()).toBe('POST');
        expect(requests[0].url()).toContain(`${PDF_URL}?download=1`);
        expect(requests[0].postDataJSON()).toMatchObject({ items: [{ appraisal_position_id: 'pos-1', amount_net: '924.00' }] });
    });

    test('print opens the same document, built from the same values, in a new tab', async ({ page, context }) => {
        const requests = await stubPdf(page);
        await open(page);

        const tab = context.waitForEvent('page');
        await print(page).click();
        await tab;

        expect(requests).toHaveLength(1);
        expect(requests[0].method()).toBe('POST');
        expect(new URL(requests[0].url()).search).toBe('');
        expect(requests[0].postDataJSON()).toMatchObject({ items: [{ amount_net: '924.00' }] });
    });

    test('both actions use the configured document', async ({ page }) => {
        const requests = await stubPdf(page);
        await open(page, '?pdfUrl=/werkstatt/angebot/andererToken/pdf');

        const saved = page.waitForEvent('download');
        await download(page).click();
        await saved;

        expect(requests[0].url()).toContain('/werkstatt/angebot/andererToken/pdf');
    });

    test('a refused draft is explained instead of failing silently', async ({ page }) => {
        await stubPdf(page, 422);
        await open(page);

        await download(page).click();

        await expect(page.getByRole('alert')).toContainText('Bitte prüfen Sie Ihre Eingaben');
    });
});

test.describe('accessibility', () => {
    test('each action has an accessible name', async ({ page }) => {
        await open(page);

        await expect(page.getByRole('button', { name: 'PDF herunterladen' })).toBeVisible();
        await expect(page.getByRole('button', { name: /Drucken/ })).toBeVisible();
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
