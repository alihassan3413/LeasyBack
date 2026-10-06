import { expect, test, type Locator, type Page } from '@playwright/test';

async function open(page: Page, query = '') {
    await page.goto(`/license-plate.html${query}`);
    await page.waitForSelector('#wrap');
}

/** The three plate inputs, in render order: district code, letters, number. */
function segment(page: Page, index: number): Locator {
    return page.getByRole('textbox').nth(index);
}

test.describe('placeholders', () => {
    test('each segment is labelled with the width it actually accepts', async ({ page }) => {
        await open(page);

        await expect(segment(page, 0)).toHaveAttribute('placeholder', 'ABC');
        await expect(segment(page, 1)).toHaveAttribute('placeholder', 'AB');
        await expect(segment(page, 2)).toHaveAttribute('placeholder', '1234');
    });

    test('no placeholder implies the electric-only suffix', async ({ page }) => {
        await open(page);

        for (const index of [0, 1, 2]) {
            await expect(segment(page, index)).not.toHaveAttribute('placeholder', /E$/);
        }
    });

    test('the hint names the format and marks the electric suffix optional', async ({ page }) => {
        await open(page);

        await expect(page.getByText('(Format: ABC AB 1234, optional E für Elektrofahrzeuge)')).toBeVisible();
    });
});

test.describe('stored plates', () => {
    test('a hyphenated plate fills the segments instead of overflowing them', async ({ page }) => {
        await open(page, '?plate=K-LB%202026');

        await expect(segment(page, 0)).toHaveValue('K');
        await expect(segment(page, 1)).toHaveValue('LB');
        await expect(segment(page, 2)).toHaveValue('2026');
        await expect(page.getByTestId('plate-error')).toHaveCount(0);
    });

    test('a hyphenated plate raises no error on the read-only edit field', async ({ page }) => {
        await open(page, '?plate=K-LB%202026&disabled=1');

        await expect(page.getByTestId('plate-error')).toHaveCount(0);
        await expect(segment(page, 0)).toBeDisabled();
    });

    test('a fully hyphenated plate splits the same way', async ({ page }) => {
        await open(page, '?plate=K-LB-2026');

        await expect(segment(page, 0)).toHaveValue('K');
        await expect(segment(page, 1)).toHaveValue('LB');
        await expect(segment(page, 2)).toHaveValue('2026');
    });

    test('a space-separated plate still splits the way it always did', async ({ page }) => {
        await open(page, '?plate=K%20LB%202026');

        await expect(segment(page, 0)).toHaveValue('K');
        await expect(segment(page, 1)).toHaveValue('LB');
        await expect(segment(page, 2)).toHaveValue('2026');
        await expect(page.getByTestId('plate-error')).toHaveCount(0);
    });

    test('a three-letter district and the electric suffix survive the split', async ({ page }) => {
        await open(page, '?plate=SÜW-AB%201234E');

        await expect(segment(page, 0)).toHaveValue('SÜW');
        await expect(segment(page, 1)).toHaveValue('AB');
        await expect(segment(page, 2)).toHaveValue('1234E');
        await expect(page.getByTestId('plate-error')).toHaveCount(0);
    });

    test('an unparseable plate still reports the error it always did', async ({ page }) => {
        await open(page, '?plate=M%20A1%202026');

        await expect(page.getByTestId('plate-error')).toHaveText(['Bitte geben Sie 1 bis 2 Buchstaben ein.']);
    });
});

test.describe('total length', () => {
    test('the widest plate the three sections accept is not rejected', async ({ page }) => {
        await open(page, '?plate=S%C3%9CW%20AB%201234E');

        await expect(page.getByTestId('plate-error')).toHaveCount(0);
    });

    test('the reported cap matches the constant the components enforce', async ({ page }) => {
        // Every section at its own maximum: 3 + 2 + 5 = 10, which the cap allows.
        await open(page, '?plate=ABC%20AB%201234E');

        await expect(page.getByTestId('plate-error')).toHaveCount(0);

        // Past what the sections accept, the sentence quotes the number the
        // constant actually holds — the bug this guards against was the message
        // still saying "8" after the cap moved.
        await open(page, '?plate=ABCD%20AB%201234E');

        await expect(page.getByText('Das Kennzeichen darf insgesamt höchstens 10 Zeichen enthalten.')).toBeVisible();
    });
});

test.describe('entry', () => {
    test('typing emits the upper-cased, space-separated plate', async ({ page }) => {
        await open(page);

        await segment(page, 0).fill('k');
        await segment(page, 1).fill('lb');
        await segment(page, 2).fill('2026');

        await expect(page.getByTestId('emitted')).toHaveText('K LB 2026');
    });

    test('the electric suffix is still accepted when typed', async ({ page }) => {
        await open(page);

        await segment(page, 0).fill('K');
        await segment(page, 1).fill('LB');
        await segment(page, 2).fill('2026E');

        await expect(page.getByTestId('emitted')).toHaveText('K LB 2026E');
        await expect(page.getByTestId('plate-error')).toHaveCount(0);
    });
});
