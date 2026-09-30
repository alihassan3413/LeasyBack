import { expect, test, type Page } from '@playwright/test';

async function open(page: Page, screen: string) {
    await page.goto(`/mfa.html?screen=${screen}`);
    await page.waitForSelector('#wrap');
}

function boxes(page: Page) {
    return page.getByTestId('otp-input').getByRole('textbox');
}

test.describe('method choice', () => {
    test('offers two methods with the authenticator recommended', async ({ page }) => {
        await open(page, 'choose');

        await expect(page.getByTestId('mfa-method-totp')).toContainText('Authenticator-App');
        await expect(page.getByTestId('mfa-method-totp')).toContainText('Empfohlen');
        await expect(page.getByTestId('mfa-method-email')).toContainText('E-Mail-Bestätigung');
        await expect(page.getByTestId('otp-input')).toHaveCount(0);
    });

    test('choosing the authenticator reveals the QR and the code entry', async ({ page }) => {
        await open(page, 'choose');
        await page.getByTestId('mfa-method-totp').click();

        await expect(page.getByTestId('mfa-qr')).toBeVisible();
        await expect(boxes(page)).toHaveCount(6);
        await expect(page.getByTestId('mfa-secret')).toHaveCount(0);
    });

    test('the setup key stays hidden until asked for', async ({ page }) => {
        await open(page, 'choose');
        await page.getByTestId('mfa-method-totp').click();
        await page.getByTestId('mfa-show-secret').click();

        await expect(page.getByTestId('mfa-secret')).toContainText('PBEKXPPJRCIM37PDCYVYVHDETLCENSDZ');
    });

    test('a method can be swapped back out', async ({ page }) => {
        await open(page, 'choose');
        await page.getByTestId('mfa-method-totp').click();
        await page.getByTestId('mfa-back').click();

        await expect(page.getByTestId('mfa-method-email')).toBeVisible();
    });
});

test.describe('otp input', () => {
    test('typing advances the focus and fills the boxes', async ({ page }) => {
        await open(page, 'verify');

        await boxes(page).first().pressSequentially('123456');

        await expect(boxes(page).nth(0)).toHaveValue('1');
        await expect(boxes(page).nth(5)).toHaveValue('6');
    });

    test('pasting a code fills every box at once', async ({ page }) => {
        await open(page, 'verify');

        await boxes(page).first().focus();
        await page.evaluate(() => {
            const data = new DataTransfer();
            data.setData('text', '654321');
            document.activeElement?.dispatchEvent(new ClipboardEvent('paste', { clipboardData: data, bubbles: true }));
        });

        await expect(boxes(page).nth(0)).toHaveValue('6');
        await expect(boxes(page).nth(5)).toHaveValue('1');
    });

    test('non-digits in a pasted value are ignored', async ({ page }) => {
        await open(page, 'verify');

        await boxes(page).first().focus();
        await page.evaluate(() => {
            const data = new DataTransfer();
            data.setData('text', '12 34-56');
            document.activeElement?.dispatchEvent(new ClipboardEvent('paste', { clipboardData: data, bubbles: true }));
        });

        await expect(boxes(page).nth(5)).toHaveValue('6');
    });

    test('backspace clears and steps back', async ({ page }) => {
        await open(page, 'verify');
        await boxes(page).first().pressSequentially('123');

        await boxes(page).nth(3).press('Backspace');
        await expect(boxes(page).nth(2)).toBeFocused();
        await expect(boxes(page).nth(2)).toHaveValue('');
    });

    test('arrow keys move between boxes', async ({ page }) => {
        await open(page, 'verify');

        await boxes(page).nth(2).focus();
        await boxes(page).nth(2).press('ArrowLeft');
        await expect(boxes(page).nth(1)).toBeFocused();

        await boxes(page).nth(1).press('ArrowRight');
        await expect(boxes(page).nth(2)).toBeFocused();
    });

    test('the submit button stays disabled until all six digits are present', async ({ page }) => {
        await open(page, 'verify');

        await expect(page.getByTestId('mfa-submit')).toBeDisabled();

        await boxes(page).first().pressSequentially('12345');
        await expect(page.getByTestId('mfa-submit')).toBeDisabled();

        await boxes(page).nth(5).pressSequentially('6');
        await expect(page.getByTestId('mfa-submit')).toBeEnabled();
    });

    test('each box is individually labelled for screen readers', async ({ page }) => {
        await open(page, 'verify');

        await expect(boxes(page).nth(0)).toHaveAccessibleName('Ziffer 1 von 6');
        await expect(boxes(page).nth(5)).toHaveAccessibleName('Ziffer 6 von 6');
    });
});

test.describe('recovery codes', () => {
    test('the way out is closed until the codes are confirmed saved', async ({ page }) => {
        await open(page, 'codes');

        await expect(page.getByTestId('mfa-codes-continue')).toBeDisabled();

        await page.getByTestId('mfa-codes-saved').check();

        await expect(page.getByTestId('mfa-codes-continue')).toBeEnabled();
    });

    test('all eight codes are listed with save, copy and print', async ({ page }) => {
        await open(page, 'codes');

        await expect(page.getByTestId('mfa-recovery-codes').getByRole('listitem')).toHaveCount(8);
        await expect(page.getByTestId('mfa-download-codes')).toBeVisible();
        await expect(page.getByTestId('mfa-copy-codes')).toBeVisible();
        await expect(page.getByTestId('mfa-print-codes')).toBeVisible();
    });

    test('downloading produces a text file of the codes', async ({ page }) => {
        await open(page, 'codes');

        const [download] = await Promise.all([page.waitForEvent('download'), page.getByTestId('mfa-download-codes').click()]);

        expect(download.suggestedFilename()).toBe('leasyback-notfallcodes.txt');
    });

    test('the screen is in German', async ({ page }) => {
        await open(page, 'codes');

        await expect(page.getByText('Notfallcodes sichern')).toBeVisible();
        await expect(page.getByText('Diese Codes werden nur einmal angezeigt.')).toBeVisible();
        await expect(page.getByText('Ich habe meine Notfallcodes gespeichert.')).toBeVisible();
    });
});

test.describe('verification screen', () => {
    test('an email challenge offers a resend with a countdown', async ({ page }) => {
        await open(page, 'verify-email');

        await expect(page.getByTestId('mfa-resend')).toBeDisabled();
        await expect(page.getByTestId('mfa-resend')).toContainText('Neuen Code in');
    });

    test('a recovery code uses a single field, not the boxes', async ({ page }) => {
        await open(page, 'verify');

        await page.getByTestId('mfa-toggle-recovery').click();

        await expect(page.getByTestId('otp-input')).toHaveCount(0);
        await expect(page.getByTestId('mfa-code')).toHaveAttribute('placeholder', 'XXXXX-XXXXX');
    });
});

test.describe('responsive', () => {
    for (const width of [390, 768]) {
        for (const screen of ['choose', 'codes', 'verify']) {
            test(`${screen} fits ${width}px without sideways scrolling`, async ({ page }) => {
                await page.setViewportSize({ width, height: 900 });
                await open(page, screen);

                expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
            });
        }
    }

    test('the otp boxes stay tappable on a phone', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await open(page, 'verify');

        const box = await boxes(page).first().boundingBox();
        expect(box?.height ?? 0).toBeGreaterThanOrEqual(44);
        expect(box?.width ?? 0).toBeGreaterThanOrEqual(32);
    });
});

test.describe('screenshots', () => {
    for (const screen of ['choose', 'totp', 'codes', 'verify', 'verify-email']) {
        test(`capture ${screen}`, async ({ page }) => {
            await page.setViewportSize({ width: 720, height: 1000 });
            await open(page, screen === 'totp' ? 'choose' : screen);

            if (screen === 'totp') {
                await page.getByTestId('mfa-method-totp').click();
                await page.getByTestId('mfa-show-secret').click();
            }

            if (screen === 'codes') {
                await page.getByTestId('mfa-codes-saved').check();
            }

            await page.locator('#wrap').screenshot({ path: `tests/layout/screenshots/mfa-${screen}.png` });
        });
    }

    test('capture mobile', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await open(page, 'choose');

        await page.locator('#wrap').screenshot({ path: 'tests/layout/screenshots/mfa-mobile.png' });
    });
});
