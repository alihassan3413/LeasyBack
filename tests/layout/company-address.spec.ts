import { expect, test, type Page, type Request } from '@playwright/test';

/**
 * The company address on the B2B registration page (B2bCompanyCard), fed by the
 * Google Places client in useGooglePlaces. Every request to Google is answered
 * here; nothing leaves the machine.
 */

interface Component {
    longText: string;
    shortText: string;
    types: string[];
}

const component = (types: string[], longText: string, shortText = longText): Component => ({ longText, shortText, types });

const FRIEDRICHSTRASSE: Component[] = [
    component(['street_number'], '12a'),
    component(['route'], 'Friedrichstraße'),
    component(['sublocality_level_1', 'sublocality', 'political'], 'Mitte'),
    component(['locality', 'political'], 'Berlin'),
    component(['administrative_area_level_1', 'political'], 'Berlin', 'BE'),
    component(['country', 'political'], 'Deutschland', 'DE'),
    component(['postal_code'], '10117'),
];

// A street-level result: no house number.
const UNTER_DEN_LINDEN: Component[] = [
    component(['route'], 'Unter den Linden'),
    component(['locality', 'political'], 'Berlin'),
    component(['country', 'political'], 'Deutschland', 'DE'),
    component(['postal_code'], '10117'),
];

const PLACES: Record<string, { main: string; secondary: string; components: Component[] }> = {
    'place-friedrich': { main: 'Friedrichstraße 12a', secondary: '10117 Berlin, Deutschland', components: FRIEDRICHSTRASSE },
    'place-linden': { main: 'Unter den Linden', secondary: 'Berlin, Deutschland', components: UNTER_DEN_LINDEN },
};

/** Answers autocomplete with every fake place whose name contains the input; details by id. */
async function fakeGoogle(page: Page): Promise<Request[]> {
    const requests: Request[] = [];

    await page.route('https://places.googleapis.com/**', async (route) => {
        const request = route.request();
        requests.push(request);
        const url = new URL(request.url());

        if (url.pathname.endsWith('places:autocomplete')) {
            const input = String(request.postDataJSON()?.input ?? '').toLowerCase();
            const suggestions = Object.entries(PLACES)
                .filter(([, place]) => place.main.toLowerCase().includes(input))
                .map(([placeId, place]) => ({
                    placePrediction: { placeId, structuredFormat: { mainText: { text: place.main }, secondaryText: { text: place.secondary } } },
                }));

            return route.fulfill({ json: { suggestions } });
        }

        const id = url.pathname.split('/').pop() ?? '';

        return route.fulfill({ json: { addressComponents: PLACES[id]?.components ?? [], location: { latitude: 52.51, longitude: 13.39 } } });
    });

    return requests;
}

/** What the page would post: the form object the fixture exposes. */
const address = (page: Page) =>
    page.evaluate(() => {
        const form = (window as unknown as { companyForm: { address: Record<string, string> } }).companyForm;

        return { ...form.address };
    });

/** Browsers expose crypto.randomUUID only in secure contexts; the AWS rehearsal is plain http. */
const asPlainHttpOrigin = (page: Page) => page.addInitScript(() => delete (Crypto.prototype as { randomUUID?: unknown }).randomUUID);

async function open(page: Page) {
    await page.goto('/company-address.html');
    await page.waitForSelector('#wrap');
}

async function pick(page: Page, typed: string, suggestion: string) {
    await page.locator('#company_street').fill(typed);
    await page.getByRole('option', { name: new RegExp(suggestion) }).click();
}

test.describe('the street field', () => {
    /** The QA bug: on http://<ip> the street input never rendered, so "Straße" could not be entered. */
    test('renders on a plain-http origin', async ({ page }) => {
        const errors: string[] = [];
        page.on('pageerror', (error) => errors.push(error.message));
        await asPlainHttpOrigin(page);
        await open(page);

        await expect(page.locator('#company_street')).toBeVisible();
        await expect(page.locator('#company_number')).toBeVisible();
        expect(errors).toEqual([]);
    });

    test('still sends a valid Places session token there', async ({ page }) => {
        const requests = await fakeGoogle(page);
        await asPlainHttpOrigin(page);
        await open(page);

        await page.locator('#company_street').fill('Friedrich');
        await expect(page.getByRole('option')).toHaveCount(1);

        const token = requests.find((r) => r.url().endsWith('places:autocomplete'))?.postDataJSON()?.sessionToken;
        expect(token).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
    });
});

test.describe('manual entry', () => {
    for (const origin of ['secure', 'plain-http'] as const) {
        test(`every field keeps exactly what was typed (${origin} origin)`, async ({ page }) => {
            if (origin === 'plain-http') await asPlainHttpOrigin(page);
            await fakeGoogle(page);
            await open(page);

            await page.locator('#company_street').fill('Am Mühlbach');
            await page.locator('#company_number').fill('3');
            await page.locator('#company_zip_code').fill('80331');
            await page.locator('#company_city').fill('München');

            expect(await address(page)).toMatchObject({
                street: 'Am Mühlbach',
                number: '3',
                zip_code: '80331',
                city: 'München',
                country: 'Deutschland',
            });
        });
    }
});

test.describe('autocomplete', () => {
    test('maps route, street_number, postal_code, locality and country to their own fields', async ({ page }) => {
        await fakeGoogle(page);
        await asPlainHttpOrigin(page);
        await open(page);

        await pick(page, 'Friedrich', 'Friedrichstraße 12a');

        await expect
            .poll(() => address(page))
            .toMatchObject({
                street: 'Friedrichstraße',
                number: '12a',
                zip_code: '10117',
                city: 'Berlin',
                country: 'Deutschland',
            });
    });

    test('never writes street text into the house number', async ({ page }) => {
        await fakeGoogle(page);
        await open(page);

        await pick(page, 'Friedrich', 'Friedrichstraße 12a');
        await expect.poll(async () => (await address(page)).street).toBe('Friedrichstraße');

        const { number } = await address(page);
        expect(number).toBe('12a');
        expect(number).not.toContain('Friedrich');
    });

    test('a street without a house number clears the number a previous pick filled in', async ({ page }) => {
        await fakeGoogle(page);
        await open(page);

        await pick(page, 'Friedrich', 'Friedrichstraße 12a');
        await expect.poll(async () => (await address(page)).number).toBe('12a');

        await page.locator('#company_zip_code').fill('');
        await pick(page, 'Linden', 'Unter den Linden');
        // The street shows the suggestion's text at once; the zip only arrives with the place details.
        await expect.poll(() => address(page)).toMatchObject({ street: 'Unter den Linden', zip_code: '10117', number: '' });
    });

    test('a street without a house number keeps a number the user typed', async ({ page }) => {
        await fakeGoogle(page);
        await open(page);

        await page.locator('#company_number').fill('7');
        await pick(page, 'Linden', 'Unter den Linden');

        await expect.poll(() => address(page)).toMatchObject({ street: 'Unter den Linden', number: '7', zip_code: '10117', city: 'Berlin' });
    });
});
