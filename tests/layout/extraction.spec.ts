import { expect, test, type Page } from '@playwright/test';

async function open(page: Page, params: Record<string, string> = {}) {
    await page.goto(`/extraction.html?${new URLSearchParams(params).toString()}`);
    await page.waitForSelector('.content-card');
}

function startButton(page: Page) {
    return page.getByRole('button', { name: /Auslese starten|Erneut auslesen/ });
}

function statusPill(page: Page) {
    return page.getByTestId('extraction-status');
}

test.describe('starting a run', () => {
    test('an order with a Gutachten offers the first run', async ({ page }) => {
        await open(page, { state: 'idle' });

        await expect(page.getByRole('heading', { name: 'Gutachten-Auslese' })).toBeVisible();
        await expect(startButton(page)).toBeEnabled();
        await expect(startButton(page)).toHaveText(/Auslese starten/);
        await expect(page.getByText('Es werden keine Positionen automatisch angelegt.')).toBeVisible();
        await expect(statusPill(page)).toHaveCount(0);
    });

    test('an order without a Gutachten PDF says what to do instead', async ({ page }) => {
        await open(page, { state: 'idle', documents: '0' });

        await expect(page.getByText('Noch kein Gutachten-PDF hinterlegt.', { exact: false })).toBeVisible();
        await expect(startButton(page)).toHaveCount(0);
    });

    test('a photo is never offered as a source', async ({ page }) => {
        await open(page, { state: 'idle', documents: '2' });

        const sources = page.getByRole('radio');

        await expect(sources).toHaveCount(2);
        await expect(page.getByText('Erstgutachten', { exact: true })).toBeVisible();
        await expect(page.getByText('Nachgutachten', { exact: true })).toBeVisible();
        await expect(page.getByText('Schadenbild 1')).toHaveCount(0);
    });

    test('the first source is preselected and another can be chosen', async ({ page }) => {
        await open(page, { state: 'idle', documents: '2' });

        const [first, second] = [page.getByRole('radio').first(), page.getByRole('radio').nth(1)];

        await expect(first).toBeChecked();

        await second.check();
        await expect(second).toBeChecked();
    });

    test('a locked order explains why the run cannot start', async ({ page }) => {
        await open(page, { state: 'idle', editable: '0' });

        await expect(page.getByText('Die Auslese ist nur zwischen Begutachtung und Angebotsfreigabe möglich.')).toBeVisible();
        await expect(startButton(page)).toBeDisabled();
    });
});

test.describe('run states', () => {
    test('a queued run confirms the upload and says analysis is about to start', async ({ page }) => {
        await open(page, { state: 'pending' });

        await expect(statusPill(page)).toHaveText('In Warteschlange');
        await expect(page.getByText('Gutachten wurde hochgeladen')).toBeVisible();
        await expect(page.getByText('Die Analyse startet in K\u00fcrze.', { exact: false })).toBeVisible();
        await expect(page.getByText('Die Karte aktualisiert sich automatisch.', { exact: false })).toBeVisible();
        await expect(startButton(page)).toBeDisabled();
    });

    test('a queued run shows no work in progress yet', async ({ page }) => {
        await open(page, { state: 'pending' });

        const steps = page.getByTestId('extraction-steps').getByRole('listitem', { includeHidden: true });

        await expect(steps).toHaveCount(4);
        await expect(page.getByTestId('extraction-steps').locator('.animate-spin')).toHaveCount(0);
    });

    test('a running extraction shows progress and blocks a second start', async ({ page }) => {
        await open(page, { state: 'processing' });

        await expect(statusPill(page)).toHaveText('Wird ausgelesen');
        await expect(page.getByText('Gutachten wird analysiert')).toBeVisible();
        await expect(page.getByText('Struktur und Schadenpositionen werden aus dem PDF gelesen.')).toBeVisible();
        await expect(page.getByText('Bitte warten Sie, bis der laufende Vorgang abgeschlossen ist.')).toBeVisible();
        await expect(startButton(page)).toBeDisabled();
    });

    test('the analysis steps describe the work without claiming exact progress', async ({ page }) => {
        await open(page, { state: 'processing' });

        const steps = page.getByTestId('extraction-steps');

        await expect(steps).toContainText('Dokument empfangen');
        await expect(steps).toContainText('Berichtsstruktur wird gelesen');
        await expect(steps).toContainText('Schadenpositionen werden ausgelesen');
        await expect(steps).toContainText('Vorschlag wird vorbereitet');

        await expect(steps.locator('.animate-spin')).toHaveCount(2);
        await expect(page.getByText('%')).toHaveCount(0);
    });

    test('the decorative steps are hidden from assistive technology', async ({ page }) => {
        await open(page, { state: 'processing' });

        await expect(page.getByTestId('extraction-steps')).toHaveAttribute('aria-hidden', 'true');
        await expect(page.getByRole('listitem').filter({ hasText: 'Berichtsstruktur wird gelesen' })).toHaveCount(0);
    });

    test('a ready run announces completion and shows the proposal summary', async ({ page }) => {
        await open(page, { state: 'ready' });

        await expect(statusPill(page)).toHaveText('Vorschlag liegt vor');
        await expect(page.getByText('Analyse abgeschlossen')).toBeVisible();
        await expect(page.getByTestId('extraction-steps')).toHaveCount(0);

        await expect(page.getByRole('definition').filter({ hasText: '12' }).first()).toBeVisible();
        await expect(page.getByText('2.416,13 €')).toBeVisible();
        await expect(page.getByText('Gutachten 42772146', { exact: false })).toBeVisible();
        await expect(page.getByText('02.12.2025', { exact: false })).toBeVisible();
    });

    test('a ready run keeps the positions untouched until the proposal is applied', async ({ page }) => {
        await open(page, { state: 'ready' });

        await expect(page.getByText('Die Gutachtenpositionen bleiben unverändert, bis Sie den Vorschlag übernehmen.')).toBeVisible();
        await expect(page.getByTestId('open-review')).toBeVisible();
        await expect(page.getByTestId('extraction-review')).toHaveCount(0);
        await expect(startButton(page)).toHaveText(/Erneut auslesen/);
    });

    test('warnings are surfaced, capped and counted', async ({ page }) => {
        await open(page, { state: 'ready' });

        await expect(page.getByText('Die erkannten Positionen liegen 583.87 unter der Gesamtsumme 3000.00.')).toBeVisible();
        await expect(page.getByText('+1 weitere Hinweise')).toBeVisible();
    });

    test('a failed run explains the reason, the manual fallback and the retry', async ({ page }) => {
        await open(page, { state: 'failed' });

        await expect(statusPill(page)).toHaveText('Fehlgeschlagen');
        await expect(page.getByText('Analyse fehlgeschlagen')).toBeVisible();
        await expect(page.getByText('vermutlich ein Scan ohne Textebene', { exact: false })).toBeVisible();
        await expect(page.getByText('Die Gutachtenpositionen können weiterhin manuell erfasst werden.')).toBeVisible();
        await expect(page.getByText('Sie können die Analyse mit „Erneut auslesen“ wiederholen.')).toBeVisible();
        await expect(page.getByText('unsupported_document', { exact: false })).toBeVisible();
        await expect(startButton(page)).toBeEnabled();
    });
});

test.describe('history', () => {
    test('earlier runs are collapsed behind a toggle', async ({ page }) => {
        await open(page, { state: 'history' });

        const toggle = page.getByRole('button', { name: 'Frühere Läufe (2)' });

        await expect(toggle).toBeVisible();
        await expect(toggle).toHaveAttribute('aria-expanded', 'false');
        await expect(page.getByText('Verworfen')).toHaveCount(0);

        await toggle.click();

        await expect(toggle).toHaveAttribute('aria-expanded', 'true');
        await expect(page.getByText('Verworfen')).toBeVisible();
    });

    test('a single run has no history section', async ({ page }) => {
        await open(page, { state: 'ready' });

        await expect(page.getByRole('button', { name: /Frühere Läufe/ })).toHaveCount(0);
    });
});

test.describe('presentation', () => {
    test('the card fits a phone without sideways scrolling', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await open(page, { state: 'ready', documents: '2' });

        expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
    });

    test('interactive targets are at least 44px on a phone', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await open(page, { state: 'idle', documents: '2' });

        for (const target of [startButton(page), page.getByRole('radio').first().locator('..')]) {
            const box = await target.boundingBox();
            expect(box?.height ?? 0).toBeGreaterThanOrEqual(44);
        }
    });

    test('the pulsing indicator is dropped under reduced motion', async ({ page }) => {
        await page.emulateMedia({ reducedMotion: 'reduce' });
        await open(page, { state: 'processing' });

        await expect(page.locator('.animate-ping')).toBeHidden();
    });

    test('the step spinners stop under reduced motion', async ({ page }) => {
        await page.emulateMedia({ reducedMotion: 'reduce' });
        await open(page, { state: 'processing' });

        await expect(page.getByTestId('extraction-steps').locator('.animate-spin').first()).toHaveCSS('animation-name', 'none');
    });

    test('the completed panel appears without motion under reduced motion', async ({ page }) => {
        await page.emulateMedia({ reducedMotion: 'reduce' });
        await open(page, { state: 'ready' });

        const panel = page.getByTestId('extraction-ready');

        await expect(panel).toBeVisible();
        await expect(panel).toHaveCSS('opacity', '1');
    });

    test('the running state stays readable on a phone', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await open(page, { state: 'processing' });

        await expect(page.getByTestId('extraction-running')).toBeVisible();
        await expect(page.getByTestId('extraction-steps')).toContainText('Schadenpositionen werden ausgelesen');
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
    });

    test('the status is announced to screen readers', async ({ page }) => {
        await open(page, { state: 'processing' });

        await expect(page.locator('[aria-live="polite"]')).toContainText('Gutachten wird analysiert');
    });

    test('the completed status is announced to screen readers', async ({ page }) => {
        await open(page, { state: 'ready' });

        await expect(page.locator('[aria-live="polite"]')).toContainText('Analyse abgeschlossen');
    });
});

test.describe('proposal review', () => {
    async function openReview(page: Page, params: Record<string, string> = {}) {
        await open(page, { state: 'ready', ...params });
        await page.getByTestId('open-review').click();
        await expect(page.getByTestId('extraction-review')).toBeVisible();
    }

    function positions(page: Page) {
        return page.getByTestId('review-position');
    }

    function applyButton(page: Page) {
        return page.getByRole('button', { name: /Position(en)? übernehmen/ });
    }

    test('the review opens from the ready state and lists every proposal line', async ({ page }) => {
        await openReview(page);

        await expect(page.getByRole('heading', { name: 'Vorschlag prüfen' })).toBeVisible();
        await expect(positions(page)).toHaveCount(3);
        await expect(page.getByText('Der ausgelesene Vorschlag bleibt unverändert.', { exact: false })).toBeVisible();
    });

    test('each position shows the extracted values, page and confidence', async ({ page }) => {
        await openReview(page);

        const first = positions(page).first();

        await expect(first.getByRole('textbox').nth(0)).toHaveValue('Stoßfänger hinten');
        await expect(first.getByRole('textbox').nth(1)).toHaveValue('Smart Repair');
        await expect(first.getByRole('textbox').nth(2)).toHaveValue('verkratzt / verschürft');
        await expect(first.getByRole('spinbutton').first()).toHaveValue('120.00');
        await expect(first.getByText('Seite 3')).toBeVisible();
        await expect(first.getByText('Sicherheit 90 %')).toBeVisible();
        await expect(positions(page).nth(1).getByText('Sicherheit 45 %')).toBeVisible();
    });

    test('the source text is available per position', async ({ page }) => {
        await openReview(page);

        const first = positions(page).first();
        await first.getByRole('button', { name: 'Quelltext aus dem Gutachten' }).click();

        await expect(first.getByText('2 Stossfänger hinten - verkratzt / verschürft - 120,00 €')).toBeVisible();
        await expect(positions(page).nth(2).getByRole('button', { name: 'Quelltext aus dem Gutachten' })).toHaveCount(0);
    });

    test('every position is selected by default and the total reflects the selection', async ({ page }) => {
        await openReview(page);

        await expect(page.getByText('3 von 3 ausgewählt · 380,00 €')).toBeVisible();
        await expect(applyButton(page)).toHaveText(/3 Positionen übernehmen/);
    });

    test('deselecting a position updates the count, total and button', async ({ page }) => {
        await openReview(page);

        await positions(page).first().getByRole('checkbox').uncheck();

        await expect(page.getByText('2 von 3 ausgewählt · 260,00 €')).toBeVisible();
        await expect(applyButton(page)).toHaveText(/2 Positionen übernehmen/);
    });

    test('deselecting everything blocks the apply action', async ({ page }) => {
        await openReview(page);

        await page.getByRole('checkbox', { name: 'Alle auswählen' }).uncheck();

        await expect(page.getByText('0 von 3 ausgewählt · 0,00 €')).toBeVisible();
        await expect(page.getByText('Wählen Sie mindestens eine Position aus.')).toBeVisible();
        await expect(applyButton(page)).toBeDisabled();
    });

    test('select all restores every position', async ({ page }) => {
        await openReview(page);

        await page.getByRole('checkbox', { name: 'Alle auswählen' }).uncheck();
        await page.getByRole('checkbox', { name: 'Alle auswählen' }).check();

        await expect(page.getByText('3 von 3 ausgewählt · 380,00 €')).toBeVisible();
        await expect(applyButton(page)).toBeEnabled();
    });

    test('values can be edited before applying', async ({ page }) => {
        await openReview(page);

        const component = positions(page).first().getByRole('textbox').first();

        await component.fill('Stoßfänger hinten links');
        await expect(component).toHaveValue('Stoßfänger hinten links');

        const amount = positions(page).first().getByRole('spinbutton').first();
        await amount.fill('150');
        await expect(page.getByText('3 von 3 ausgewählt · 410,00 €')).toBeVisible();
    });

    test('a deselected position cannot be edited', async ({ page }) => {
        await openReview(page);

        await positions(page).first().getByRole('checkbox').uncheck();

        await expect(positions(page).first().getByRole('textbox').first()).toBeDisabled();
    });

    test('the review can be closed again', async ({ page }) => {
        await openReview(page);

        await page.getByRole('button', { name: 'Prüfung schließen' }).click();

        await expect(page.getByTestId('extraction-review')).toHaveCount(0);
        await expect(page.getByTestId('open-review')).toBeVisible();
    });

    test('a locked order offers no review at all', async ({ page }) => {
        await open(page, { state: 'ready', editable: '0' });

        await expect(page.getByTestId('open-review')).toHaveCount(0);
        await expect(page.getByText('Übernehmen ist nur zwischen Begutachtung und Angebotsfreigabe möglich.')).toBeVisible();
    });

    test('the applied state reports who applied the proposal and when', async ({ page }) => {
        await open(page, { state: 'applied' });

        await expect(page.getByTestId('extraction-status')).toHaveText('Übernommen');
        await expect(page.getByText('Positionen wurden übernommen')).toBeVisible();
        await expect(page.getByText('durch Admin Person', { exact: false })).toBeVisible();
        await expect(page.getByTestId('open-review')).toHaveCount(0);
        await expect(page.getByTestId('extraction-review')).toHaveCount(0);
    });

    test('the review works on a phone without sideways scrolling', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await openReview(page);

        await expect(positions(page).first()).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);

        const box = await applyButton(page).boundingBox();
        expect(box?.height ?? 0).toBeGreaterThanOrEqual(44);
    });

    test('the review is labelled for assistive technology', async ({ page }) => {
        await openReview(page);

        await expect(positions(page).first().getByRole('checkbox')).toHaveAccessibleName('Position 1 übernehmen');
        await expect(page.getByRole('checkbox', { name: 'Alle auswählen' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Prüfung schließen' })).toBeVisible();
        await expect(page.getByText('3 von 3 ausgewählt · 380,00 €')).toBeVisible();
    });
});

test.describe('damage image suggestions', () => {
    async function openReview(page: Page) {
        await open(page, { state: 'ready' });
        await page.getByTestId('open-review').click();
        await expect(page.getByTestId('extraction-review')).toBeVisible();
    }

    function position(page: Page, index: number) {
        return page.getByTestId('review-position').nth(index);
    }

    test('suggested images are preselected and labelled as auto-matched', async ({ page }) => {
        await openReview(page);

        const badge = position(page, 0).getByTestId('suggestion-badge');

        await expect(badge).toHaveText('Automatisch zugeordnet über Beschädigung 1');
        await expect(position(page, 0).getByText('Schadenbilder Position 1: 1 von 3 ausgewählt')).toBeVisible();
        await expect(position(page, 1).getByTestId('suggestion-badge')).toHaveText('Automatisch zugeordnet über Bauteil und Reparaturweg');
        await expect(position(page, 1).getByText('Schadenbilder Position 2: 2 von 3 ausgewählt')).toBeVisible();
    });

    test('thumbnails render for every available image', async ({ page }) => {
        await openReview(page);

        const tiles = position(page, 0).getByRole('list', { name: 'Schadenbilder Position 1' }).getByRole('listitem');

        await expect(tiles).toHaveCount(3);
        await expect(tiles.first().locator('img')).toHaveAttribute('src', /^data:image\/svg\+xml/);
        await expect(tiles.first().getByRole('button', { name: 'Bild 1: Schadenbild 1', exact: true })).toHaveAttribute('aria-pressed', 'true');
        await expect(tiles.nth(1).getByRole('button', { name: 'Bild 2: Schadenbild 2', exact: true })).toHaveAttribute('aria-pressed', 'false');
    });

    test('a position without a suggestion starts with nothing selected', async ({ page }) => {
        await openReview(page);

        await expect(position(page, 2).getByTestId('suggestion-badge')).toHaveCount(0);
        await expect(position(page, 2).getByText('Schadenbilder Position 3: 0 von 3 ausgewählt')).toBeVisible();
    });

    test('removing a suggested image updates the selection and flags the change', async ({ page }) => {
        await openReview(page);

        await position(page, 0).getByRole('button', { name: 'Bild 1: Schadenbild 1', exact: true }).click();

        await expect(position(page, 0).getByText('Schadenbilder Position 1: 0 von 3 ausgewählt')).toBeVisible();
        await expect(position(page, 0).getByTestId('suggestion-badge')).toHaveText('Vorschlag angepasst');
    });

    test('an admin can add an image the matcher did not suggest', async ({ page }) => {
        await openReview(page);

        await position(page, 2).getByRole('button', { name: 'Bild 3: Schadenbild 3', exact: true }).click();

        await expect(position(page, 2).getByText('Schadenbilder Position 3: 1 von 3 ausgewählt')).toBeVisible();
    });

    test('images cannot be changed on a deselected position', async ({ page }) => {
        await openReview(page);

        await position(page, 0).getByRole('checkbox').first().uncheck();

        await expect(position(page, 0).getByRole('button', { name: 'Bild 2: Schadenbild 2', exact: true })).toBeDisabled();
    });

    test('the image picker stays usable on a phone', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await openReview(page);

        await expect(position(page, 0).getByRole('list', { name: 'Schadenbilder Position 1' })).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);

        const box = await position(page, 0).getByRole('button', { name: 'Bild 1: Schadenbild 1', exact: true }).boundingBox();
        expect(box?.height ?? 0).toBeGreaterThanOrEqual(44);
    });
});
