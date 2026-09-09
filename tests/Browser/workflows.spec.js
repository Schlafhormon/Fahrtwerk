const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');

// Run only against explicitly seeded local/testing data. Passwords are never logged.
const credentialFile = process.env.E2E_CREDENTIAL_FILE || 'storage/app/private/demo-credentials.txt';
function credentials(username) {
    if (!fs.existsSync(credentialFile)) throw new Error('Zuerst ausdrücklich lokale Demo-Daten erzeugen; siehe README.');
    const line = fs.readFileSync(credentialFile, 'utf8').split('\n').find(line => line.startsWith(username + ': '));
    if (!line) throw new Error('Demo-Zugang fehlt.');
    return line.slice(username.length + 2).trim();
}
async function login(page, username) {
    let password = credentials(username);
    await page.goto('/anmelden');
    await page.locator('#username').fill(username);
    await page.locator('#password').fill(password);
    await page.getByRole('button', { name: 'Anmelden', exact: true }).click();
    await page.waitForURL(url => !url.pathname.endsWith('anmelden'));
    if (new URL(page.url()).pathname === '/passwort') {
        const replacement = crypto.randomBytes(24).toString('base64url');
        await page.locator('#current_password').fill(password);
        await page.locator('#password').fill(replacement);
        await page.locator('#password_confirmation').fill(replacement);
        await page.getByRole('button', { name: 'Passwort speichern', exact: true }).click();
        await expect(page).toHaveURL(/\/$/);
        const content = fs.readFileSync(credentialFile, 'utf8');
        fs.writeFileSync(credentialFile, content.replace(username + ': ' + password, username + ': ' + replacement), { mode: 0o600 });
    }
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Guten Tag');
}

test('Login, Passwortwechsel, Fahrtentwurf, Vorlagen, Auswertung und responsive Oberfläche', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto('/anmelden');
    await page.screenshot({ path: 'docs/qa/login-desktop.png', fullPage: true });
    await login(page, 'demo.meyer');
    await page.screenshot({ path: 'docs/qa/dashboard-desktop.png', fullPage: true });
    await page.goto('/fahrten/create');
    await expect(page.getByLabel('Fahrzeug *')).toHaveValue(/\d+/);
    await page.getByLabel('Ziel', { exact: true }).fill('Browser-Test Bürgerzentrum');
    await page.getByLabel('Reiseroute', { exact: true }).fill('Rathaus → Bürgerzentrum → Rathaus');
    await page.getByLabel('Zweck der Fahrt', { exact: true }).fill('Funktionstest der Fahrterfassung');
    await page.getByLabel('Fahrtbeginn', { exact: true }).fill('13:00');
    await page.getByLabel('Fahrtende', { exact: true }).fill('14:00');
    await page.getByLabel('Kilometerstand Beginn', { exact: true }).fill('15000');
    await page.getByLabel('Kilometerstand Ende', { exact: true }).fill('15020');
    await page.getByLabel('Geschäftlich (km)', { exact: true }).fill('20');
    await expect(page.locator('#distance-message')).toContainText('stimmt');
    await page.getByRole('button', { name: 'Entwurf speichern', exact: true }).click();
    await expect(page.getByRole('status').first()).toContainText('gespeichert');
    await page.screenshot({ path: 'docs/qa/trip-desktop.png', fullPage: true });
    const tripUrl = page.url();
    await page.goto('/auswertungen');
    await expect(page.getByRole('heading', { name: 'Auswertungen', exact: true })).toBeVisible();
    await page.screenshot({ path: 'docs/qa/reports-desktop.png', fullPage: true });
    await page.goto('/admin/mitarbeitende');
    await expect(page.getByText('403', { exact: true })).toBeVisible();
    await page.goto('/');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.screenshot({ path: 'docs/qa/dashboard-mobile.png', fullPage: true });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
    await page.getByRole('button', { name: 'Navigation öffnen' }).click();
    await expect(page.getByRole('navigation', { name: 'Hauptnavigation' })).toBeVisible();
    await page.goto(tripUrl);
    page.once('dialog', dialog => dialog.accept());
    await page.getByRole('button', { name: 'Entwurf löschen', exact: true }).click();
    await expect(page).toHaveURL(/\/fahrten$/);
    expect(errors).toEqual([]);
});

test('Adminprüfung, PDF/XLSX-Downloads und CSRF-Schutz über echte HTTP-Sitzung', async ({ page, context }) => {
    await login(page, 'demo.admin');
    await page.goto('/abrechnungen');
    await page.getByLabel('Status', { exact: true }).selectOption('LOCKED');
    await page.getByRole('button', { name: 'Filtern', exact: true }).click();
    await page.getByRole('link', { name: 'Öffnen →' }).first().click();
    const downloadPromise = page.waitForEvent('download');
    await page.getByRole('link', { name: 'Abrechnung als PDF' }).click();
    const download = await downloadPromise;
    await download.saveAs('docs/qa/abrechnung-demo.pdf');
    expect(fs.readFileSync('docs/qa/abrechnung-demo.pdf').subarray(0,4).toString()).toBe('%PDF');
    await page.goto('/fahrten');
    const xlsxPromise = page.waitForEvent('download');
    await page.getByRole('link', { name: 'Excel', exact: true }).click();
    await (await xlsxPromise).saveAs('docs/qa/fahrten-demo.xlsx');
    expect(fs.readFileSync('docs/qa/fahrten-demo.xlsx').subarray(0,2).toString()).toBe('PK');
    const blocked = await context.request.post('/admin/organisationseinheiten', { form: { name: 'CSRF-BYPASS', active: '1' } });
    expect(blocked.status()).toBe(419);
    await page.goto('/admin/audit');
    await expect(page.getByRole('heading', { name: 'Audit-Log', exact: true })).toBeVisible();
    await page.screenshot({ path: 'docs/qa/audit-desktop.png', fullPage: true });
    const cookies = await context.cookies();
    const session = cookies.find(c => c.httpOnly);
    expect(session).toBeTruthy();
    expect(session.sameSite).toBe('Lax');
});
