import { expect, test, type Page } from '@playwright/test';
import { mkdirSync, writeFileSync } from 'node:fs';
import { addPosition, ADMIN, chooseOption, chooseSearchable, CUSTOMER, dismissWelcome, expectNextTask, positionsCard, quotationsCard, loginAs, openTheOrder, pickFutureDate, runTaskAction, STATION, uploadReport } from './helpers';

/**
 * The whole B2C journey, in order, in one browser.
 *
 * Serial by necessity rather than by preference: every step consumes the state
 * the previous one produced, and there is a single database behind the suite.
 */
test.describe.configure({ mode: 'serial' });

let page: Page;

test.beforeAll(async ({ browser }) => {
    page = await browser.newPage();
});

test.afterAll(async () => {
    await page.close();
});

function debugSnap(name: string, body: string) {
    mkdirSync('test-results/snapshots', { recursive: true });
    writeFileSync(`test-results/snapshots/${name}.txt`, body, 'utf8');
}

async function dump(name: string) {
    const text = await page.locator('body').innerText();
    const aria = await page
        .locator('body')
        .ariaSnapshot()
        .catch(() => '<unavailable>');

    debugSnap(name, `URL: ${page.url()}\n\n=== TEXT ===\n${text}\n\n=== ARIA ===\n${aria}`);
}

test('01 — the customer completes their profile', async () => {
    await loginAs(page, CUSTOMER);
    await page.goto('/onboarding');
    await expect(page.getByRole('heading', { name: 'Kundendaten', level: 2 })).toBeVisible();

    await chooseOption(page, /anrede/i, 'Frau');
    await page.getByRole('textbox', { name: /vorname/i }).fill('Erika');
    await page.getByRole('textbox', { name: /nachname/i }).fill('Mustermann');
    await page.getByRole('combobox', { name: /straße/i }).fill('Musterstraße');
    await page.getByRole('textbox', { name: /^nr\./i }).fill('12');
    await page.getByRole('textbox', { name: /plz/i }).fill('10115');
    await page.getByRole('textbox', { name: /^ort/i }).fill('Berlin');
    await page.getByRole('textbox', { name: /telefonnummer/i }).first().fill('3012345678');

    await page.getByRole('button', { name: 'Weiter' }).click();

    // The wizard is one Inertia page that re-renders into the next step, so the
    // URL never changes — the heading is the only honest signal it advanced.
    await expect(page.getByRole('heading', { level: 2 })).not.toHaveText('Kundendaten');

    await expect(page.getByRole('heading', { name: 'Fahrzeugdaten', level: 2 })).toBeVisible();
});

test('02 — the customer registers their vehicle', async () => {
    await page.getByRole('textbox', { name: 'Unterscheidungszeichen' }).fill('B');
    await page.getByRole('textbox', { name: 'Erkennungszeichen' }).fill('LB');
    await page.getByRole('textbox', { name: 'Erkennungsnummer' }).fill('2026E');
    await page.getByRole('textbox', { name: /^FIN/ }).fill('WVWZZZ1JZXW000001');

    await chooseSearchable(page, /^Marke/, 'Volkswagen', 'Volkswagen');

    await page.getByRole('textbox', { name: 'Modell' }).fill('Passat');
    await page.getByRole('textbox', { name: /^Leasinggeber/ }).fill('Muster Leasing GmbH');

    // Skips the date picker: the wizard offers this exact escape hatch for a
    // customer who does not have the leasing end date to hand.
    await page.getByRole('checkbox', { name: /Datum des Leasingendes/ }).check();

    await page.getByRole('button', { name: 'Weiter' }).click();
    await expect(page.getByRole('heading', { name: /Prüfstation/ })).toBeVisible();
});

test('03 — the customer books an inspection appointment', async () => {
    // A DEKRA station on purpose: booking at a TÜV SÜD one calls the live
    // partner API, which would make the suite depend on a third party.
    await chooseSearchable(page, /^Prüfstation/, 'DEKRA E2E', new RegExp(STATION), false);
    await pickFutureDate(page, /^Datum/);
    await chooseOption(page, /^Uhrzeit/, /\d{2}:\d{2}/);
    await page.getByRole('textbox', { name: 'Bemerkungen' }).fill('E2E Testbuchung');
    await page.getByRole('checkbox', { name: /Bearbeitungsgebühr/ }).check();

    await page.getByRole('button', { name: 'Termin buchen' }).click();

    // The wizard stays put and turns into a confirmation panel carrying the new
    // order number, so that — not a navigation — is the signal it worked.
    await expect(page.getByText(/^BLB/)).toBeVisible({ timeout: 30_000 });

    await dump('04-booking-confirmation');
});

test('04 — the new order reaches the customer dashboard', async () => {
    await page.goto('/dashboard');
    await page.waitForLoadState('networkidle');
    await dismissWelcome(page);
    await dump('05-customer-dashboard');

    await expect(page.getByText(/B\s*LB\s*2026E/).first()).toBeVisible();
});

test('05 — admin sees the new order and its next task', async () => {
    await loginAs(page, ADMIN);
    await openTheOrder(page);
    await dump('06-admin-order');
});

test('06 — admin confirms the inspection appointment', async () => {
    await expectNextTask(page, 'Begutachtungstermin bestätigen');
    await runTaskAction(page, 'Termin bestätigen');

    await expectNextTask(page, 'Erstgutachten hochladen');
});

test('07 — admin publishes the initial report', async () => {
    await uploadReport(page, 'Gutachten');

    await openTheOrder(page);
    await expectNextTask(page, 'Erstbegutachtung abschließen');
});

test('08 — admin completes the inspection', async () => {
    await runTaskAction(page, 'Begutachtung abschließen');

    await expectNextTask(page, 'Reparaturpositionen erfassen');
});

test('09 — admin records the repair positions', async () => {
    await addPosition(page, 0, 'Stoßfänger vorne', 'Kratzer', '450', 'Instandsetzung');
    await addPosition(page, 1, 'Tür hinten links', 'Delle', '380', 'Lackierung');

    await positionsCard(page).getByRole('button', { name: 'Positionen speichern' }).click();
    await page.waitForLoadState('networkidle');
    await dump('07-positions-saved');
    await expect(page.getByText('830,00').first()).toBeVisible();

    await expectNextTask(page, 'Werkstattangebote anfragen');
});

const workshopLinks: string[] = [];

test('10 — admin invites two workshops', async () => {
    for (const [name, email] of [
        ['Karosserie Meier GmbH', 'meier@werkstatt.test'],
        ['Auto Service Schmidt', 'schmidt@werkstatt.test'],
    ]) {
        const card = quotationsCard(page);

        await card.getByPlaceholder('Name der Werkstatt').fill(name);
        // By input type: the "E-Mail (optional)" label carries no `for` and does
        // not wrap its input, so the field has no accessible name to query by.
        await card.locator('input[type="email"]').fill(email);
        await card.getByRole('button', { name: 'Link erstellen' }).click();
        await page.waitForLoadState('networkidle');

        // One banner exists at a time and it is replaced in place, so the signal
        // that this invite produced its own link is the text having changed.
        const banner = page.getByText(/werkstatt\/angebot\//).first();
        await expect(banner).toBeVisible();

        const previous = workshopLinks.at(-1);

        if (previous) {
            await expect(banner).not.toHaveText(previous);
        }

        workshopLinks.push((await banner.innerText()).trim());
    }

    await dump('08-two-workshops-invited');

    expect(workshopLinks).toHaveLength(2);
    expect(workshopLinks[0]).not.toBe(workshopLinks[1]);
    await expectNextTask(page, 'Werkstattangebote abwarten');
});

/**
 * A workshop has no account, so it gets its own browser context — that also
 * proves the link works with no session at all.
 */
async function submitAsWorkshop(link: string, company: string, prices: string[]) {
    const context = await page.context().browser()!.newContext();
    const shop = await context.newPage();

    await shop.goto(link);
    await expect(shop.getByRole('heading', { name: 'Angebot abgeben', level: 1 })).toBeVisible();

    debugSnap('09-workshop-form', `URL: ${shop.url()}\n\n${await shop.locator('body').innerText()}`);

    // The workshop sees the car and the damage — and must not see the customer.
    await expect(shop.getByText('B LB 2026E')).toBeVisible();
    await expect(shop.getByText(/e2e\.customer@|Mustermann|Musterstraße/)).toHaveCount(0);

    // By position, not by label: none of these inputs has an accessible name —
    // the German text above each one is a plain <span>, not a bound <label>.
    const fields = shop.getByRole('textbox');
    await fields.nth(0).fill(company);
    await fields.nth(1).fill('Hans Meier');
    await fields.nth(2).fill('kontakt@werkstatt.test');
    await fields.nth(3).fill('030 1234567');

    // Spinbutton 0 is "Bearbeitungsdauer"; the position prices follow it.
    const numbers = shop.getByRole('spinbutton');
    await numbers.nth(0).fill('3');

    for (const [index, price] of prices.entries()) {
        await numbers.nth(index + 1).fill(price);
    }

    await shop.getByRole('button', { name: 'Angebot verbindlich senden' }).click();
    await expect(shop.getByText(/Vielen Dank/)).toBeVisible({ timeout: 20_000 });

    await context.close();
}

test('11 — the cheaper workshop submits its quotation', async () => {
    await submitAsWorkshop(workshopLinks[0], 'Karosserie Meier GmbH', ['400', '340']);
});

test('12 — the more expensive workshop submits too', async () => {
    await submitAsWorkshop(workshopLinks[1], 'Auto Service Schmidt', ['480', '420']);

    await openTheOrder(page);
    await dump('10-both-quotations-in');
    await expectNextTask(page, 'Kundenangebot erstellen');
});

const TAKE_OFFER = 'Als Kundenangebot übernehmen';

async function takeQuotationAsOffer(workshop: string) {
    // The innermost block that holds both this workshop's name and its own
    // button — filtering on the name alone matches wrappers that do not
    // contain the button, and on the button alone matches every row.
    const row = quotationsCard(page)
        .locator('div')
        .filter({ hasText: workshop })
        .filter({ has: page.getByRole('button', { name: TAKE_OFFER }) })
        .last();

    await row.getByRole('button', { name: TAKE_OFFER }).click();
    await page.waitForLoadState('networkidle');
}

async function publishLatestOffer() {
    const offers = page.locator('.content-card').filter({ hasText: 'Angebote' });
    await offers.getByRole('button', { name: 'Veröffentlichen' }).last().click();
    await page.waitForLoadState('networkidle');
}

test('13 — admin turns the cheaper quotation into a customer offer', async () => {
    await takeQuotationAsOffer('Karosserie Meier GmbH');
    await dump('11-offer-draft');

    await expectNextTask(page, 'Kundenangebot veröffentlichen');

    await publishLatestOffer();
    await expectNextTask(page, 'Entscheidung des Kunden abwarten');
});

test('14 — the customer sees a gross offer and rejects it', async () => {
    await loginAs(page, CUSTOMER);
    await page.goto('/dashboard');
    await dismissWelcome(page);
    await page.getByRole('button', { name: 'Details öffnen' }).first().click();
    await page.waitForLoadState('networkidle');
    await dump('12-customer-offer');

    // A private customer is quoted gross; 740,00 net at 19 % is 880,60.
    await expect(page.getByText('880,60').first()).toBeVisible();
});

async function openCustomerPanel() {
    await page.goto('/dashboard');
    await dismissWelcome(page);
    await page.getByRole('button', { name: 'Details öffnen' }).first().click();
    await page.waitForLoadState('networkidle');

    // The panel mounts and then re-renders once its data arrives, detaching the
    // first set of nodes. Anchoring on content that only exists after that
    // second render is what makes the following clicks land on a stable DOM.
    await expect(page.getByText(/^Reparaturangebot$/i).first()).toBeVisible();
    await page.waitForTimeout(500);
}

test('15 — rejecting an offer is acknowledged, not silently rewound', async () => {
    await openCustomerPanel();

    await page.getByRole('button', { name: 'Angebot ablehnen' }).first().click();
    await page.waitForLoadState('networkidle');
    await dump('13-after-rejection');

    // The bug this pins: the timeline used to fall back to "Erstbegutachtung
    // abgeschlossen" with its ordinary wording, so a customer who had just
    // declined an offer saw no trace of having decided anything.
    await expect(page.getByText(/Sie haben das letzte Angebot abgelehnt/)).toBeVisible();
});

test('16 — admin builds a replacement offer from the other workshop', async () => {
    await loginAs(page, ADMIN);
    await openTheOrder(page);

    await expectNextTask(page, 'Kundenangebot erstellen');

    await takeQuotationAsOffer('Auto Service Schmidt');
    await publishLatestOffer();

    await expectNextTask(page, 'Entscheidung des Kunden abwarten');
});

test('17 — the accepted offer is the one the customer sees, not the rejected one', async () => {
    await loginAs(page, CUSTOMER);
    await openCustomerPanel();

    await page.getByRole('button', { name: 'Reparatur freigeben' }).first().click();
    await page.waitForLoadState('networkidle');

    await openCustomerPanel();
    await dump('14-after-acceptance');

    // The bug this pins: both offers stay in the payload ordered by sequence,
    // so resolving "the decided offer" by position landed on the rejected one —
    // the panel showed the losing workshop and its prices.
    await expect(page.getByText('Ausführende Werkstatt: Auto Service Schmidt')).toBeVisible();
    await expect(page.getByText('Ausführende Werkstatt: Karosserie Meier GmbH')).toHaveCount(0);

    // 900,00 net at 19 % — the accepted workshop's price, not the rejected one's.
    await expect(page.getByText('1.071,00').first()).toBeVisible();
});
