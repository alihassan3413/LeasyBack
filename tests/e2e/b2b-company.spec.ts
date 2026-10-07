import {
    BUSY_PLATE,
    COMPANY,
    expect,
    FOREIGN_PLATE,
    FREE_PLATES,
    INVITATION_TOKEN,
    loginAs,
    PASSWORD,
    SECOND_COMPANY,
    submitLogin,
    test,
    USERS,
} from './fixtures';

test.describe('Company Administrator', () => {
    test('invites a member, resends and revokes the invitation', async ({ page }) => {
        await loginAs(page, USERS.owner);
        await page.goto('/company/members');

        await page.getByRole('button', { name: 'Einladen' }).first().click();

        // The company has its administrator already: that role is not on offer, the others are.
        const invite = page.getByRole('dialog');
        await expect(invite.getByText('Standardnutzer', { exact: true })).toBeVisible();
        await expect(invite.getByText('Nur-Lese-Zugriff', { exact: true })).toBeVisible();
        await expect(invite.getByText('Unternehmens-Administrator', { exact: true })).toHaveCount(0);

        await page.locator('#invite_email').fill('e2e.neu@leasyback.test');
        await page.getByRole('button', { name: 'Einladung senden' }).click();

        const row = page.getByRole('listitem').filter({ hasText: 'e2e.neu@leasyback.test' });
        await expect(row).toBeVisible();

        await row.getByRole('button', { name: 'Einladung erneut senden' }).click();
        await expect(row.getByRole('button', { name: 'Einladung erneut senden' })).toBeEnabled();

        await row.getByRole('button', { name: 'Einladung zurückziehen' }).click();
        await expect(page.getByText('e2e.neu@leasyback.test')).toHaveCount(0);
    });

    test('exports the statistics as Excel', async ({ page }) => {
        await loginAs(page, USERS.owner);
        await page.goto('/company/statistics');

        const download = page.waitForEvent('download');
        await page.getByRole('link', { name: /Excel-Export/ }).click();
        expect((await download).suggestedFilename()).toMatch(/\.xlsx$/);
    });

    test('switches to a second company and sees only its vehicles', async ({ page }) => {
        await loginAs(page, USERS.owner);
        await page.goto('/fahrzeuge');
        await expect(page.getByText(FREE_PLATES[0]).first()).toBeVisible();

        await page
            .getByRole('button', { name: /Bereich wechseln/ })
            .first()
            .click();
        await page.getByRole('menuitem', { name: new RegExp(SECOND_COMPANY) }).click();
        await expect(page.getByRole('button', { name: new RegExp(`Aktiver Bereich: ${SECOND_COMPANY}`) }).first()).toBeVisible();

        await page.goto('/fahrzeuge');
        await expect(page.getByText('HH-ZW 2001').first()).toBeVisible();
        await expect(page.getByText(FREE_PLATES[0])).toHaveCount(0);

        // Read-only in the second company: nothing to book or add there.
        await expect(page.getByRole('button', { name: /Neues Fahrzeug anlegen/ })).toHaveCount(0);

        await page
            .getByRole('button', { name: /Bereich wechseln/ })
            .first()
            .click();
        await page.getByRole('menuitem', { name: new RegExp(COMPANY) }).click();
        await expect(page.getByRole('button', { name: new RegExp(`Aktiver Bereich: ${COMPANY}`) }).first()).toBeVisible();
    });
});

test.describe('Standard User', () => {
    test.beforeEach(async ({ page }) => {
        await submitLogin(page, USERS.standard);
    });

    test('adds a vehicle', async ({ page }) => {
        await page.goto('/fahrzeuge');
        await page.getByRole('button', { name: /Neues Fahrzeug anlegen/ }).click();

        const form = page.getByRole('dialog', { name: 'Neues Fahrzeug anlegen' });
        await form.getByLabel('Unterscheidungszeichen').fill('B');
        await form.getByLabel('Erkennungszeichen').fill('EZ');
        await form.getByLabel('Erkennungsnummer').fill('4242');
        await form.getByLabel(/FIN/).fill('WVWZZZ1KZE2E04242');
        await form.getByRole('button', { name: 'Marke (erforderlich)' }).click();
        await page.getByPlaceholder('Marke suchen...').fill('Volkswagen');
        await page.getByRole('button', { name: 'Volkswagen', exact: true }).click();
        await form.getByPlaceholder('Leasinggeber eingeben').fill('VW Leasing');

        await form.getByRole('button', { name: 'Fahrzeug anlegen' }).click();
        await expect(form).toBeHidden();
        await expect(page.getByText(/B[ -]EZ[ -]4242/).first()).toBeVisible();
    });

    test('cannot manage members', async ({ page, problems }) => {
        problems.allow(/status of 403/);
        const response = await page.goto('/company/members');

        expect(response?.status()).toBe(403);
    });

    test('writes a message on a company order', async ({ page }) => {
        await page.goto('/auftraege');
        await page.getByText(BUSY_PLATE).first().click();
        await expect(page).toHaveURL(/\/orders\//);

        await page.getByPlaceholder(/Nachricht schreiben/).fill('Abholung bitte vormittags');
        await page.getByRole('button', { name: 'Nachricht senden' }).click();
        await expect(page.getByText('Abholung bitte vormittags')).toBeVisible();
    });
});

test.describe('Read-only User', () => {
    test('sees the fleet but cannot add, book or manage', async ({ page, problems }) => {
        problems.allow(/status of 403/);
        await submitLogin(page, USERS.readOnly);

        await page.goto('/fahrzeuge');
        await expect(page.getByText(FREE_PLATES[0]).first()).toBeVisible();
        await expect(page.getByRole('button', { name: /Neues Fahrzeug anlegen/ })).toHaveCount(0);

        expect((await page.goto('/company/members'))?.status()).toBe(403);
    });
});

test.describe('company isolation', () => {
    test('another company’s data is never shown, unknown ids are 404', async ({ page, problems }) => {
        problems.allow(/status of 404/);
        await submitLogin(page, USERS.standard);

        await page.goto('/fahrzeuge');
        await expect(page.getByText(FREE_PLATES[0]).first()).toBeVisible();
        await expect(page.getByText(FOREIGN_PLATE)).toHaveCount(0);

        expect((await page.goto('/orders/00000000-0000-4000-8000-000000000000'))?.status()).toBe(404);
        expect((await page.goto('/fahrzeuge/not-a-uuid'))?.status()).toBe(404);
    });
});

test.describe('invitation', () => {
    test('a new person accepts the invitation by registering', async ({ page }) => {
        await page.goto(`/invitations/${INVITATION_TOKEN}`);
        await expect(page.getByText(COMPANY).first()).toBeVisible();

        await page
            .getByRole('link', { name: 'Konto erstellen' })
            .or(page.getByRole('button', { name: 'Konto erstellen' }))
            .first()
            .click();
        await expect(page.getByLabel('E-Mail-Adresse')).toHaveValue(USERS.invitee);
        await page.getByRole('textbox', { name: /^Passwort/ }).fill(`${PASSWORD}-Neu1!`);

        await page.getByRole('button', { name: 'Registrieren und beitreten' }).click();
        await page.waitForURL((url) => !/register|invitations/.test(url.pathname), { timeout: 20_000 });

        await page.goto('/fahrzeuge');
        await expect(page.getByText(FREE_PLATES[0]).first()).toBeVisible();
    });
});
