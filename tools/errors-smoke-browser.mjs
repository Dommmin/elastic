// Krok przeglądarkowy `make errors-smoke` (ETAP 11b): otwiera stronę, która rzuca
// błąd po zamontowaniu, i czeka, aż SDK wyśle kopertę przez tunel /_errors.
// Użycie (z katalogu, w którym zainstalowano playwright-core):
//   node errors-smoke-browser.mjs <url>
// Używa zainstalowanego Google Chrome (channel: 'chrome'), bez pobierania przeglądarek.
import { chromium } from 'playwright-core';

const url = process.argv[2];

if (!url) {
    console.error('Podaj URL strony errors-smoke.');
    process.exit(2);
}

const browser = await chromium.launch({ channel: 'chrome', headless: true });

try {
    // catalog.localhost ma certyfikat lokalnego CA Caddy'ego, któremu przeglądarka nie ufa.
    const context = await browser.newContext({ ignoreHTTPSErrors: true });
    const page = await context.newPage();

    const tunnel = page.waitForResponse(
        (r) => r.url().endsWith('/_errors') && r.request().method() === 'POST',
        { timeout: 20_000 },
    );

    await page.goto(url, { waitUntil: 'load' });
    const response = await tunnel;

    console.log(`tunel /_errors: HTTP ${response.status()}`);
    process.exitCode = response.status() === 200 ? 0 : 1;
} catch (error) {
    console.error(`Brak wysyłki przez tunel: ${error.message}`);
    process.exitCode = 1;
} finally {
    await browser.close();
}
