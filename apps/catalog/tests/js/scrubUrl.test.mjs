// Test czystych funkcji czyszczących zdarzenia przeglądarki (ADR-0009, zasada 6).
// Uruchamiany wbudowanym runnerem Node'a: `npm run test:js` (bez nowych zależności).
import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    scrubBreadcrumb,
    scrubEvent,
    scrubUrl,
} from '../../resources/js/lib/scrubUrl.ts';

test('token resetu hasła w ścieżce i e-mail w query stringu znikają', () => {
    assert.equal(
        scrubUrl(
            'https://catalog.localhost:8443/reset-password/SECRETTOKEN?email=jan%40example.com',
        ),
        'https://catalog.localhost:8443/reset-password/[Filtered]',
    );
});

test('link weryfikacji e-maila traci id, hash i podpis', () => {
    assert.equal(
        scrubUrl('/email/verify/7/HASHSECRET?expires=1&signature=SIGSECRET'),
        '/email/verify/[Filtered]',
    );
});

test('zwykły adres traci tylko query string i fragment', () => {
    assert.equal(scrubUrl('/search?q=laptop#wyniki'), '/search');
    assert.equal(scrubUrl('/products/12'), '/products/12');
});

test('zdarzenie: url, Referer w obu zapisach i query_string są czyszczone', () => {
    const event = scrubEvent({
        request: {
            url: 'https://x.test/reset-password/SECRETTOKEN?email=a%40b.c',
            query_string: 'email=a%40b.c',
            headers: {
                Referer: 'https://x.test/email/verify/7/HASH?signature=SIG',
                referer: 'https://x.test/?token=abc',
                'User-Agent': 'Chrome',
            },
        },
    });

    const serialized = JSON.stringify(event);
    assert.equal(event.request.url, 'https://x.test/reset-password/[Filtered]');
    assert.equal(event.request.query_string, undefined);
    assert.equal(event.request.headers['User-Agent'], 'Chrome');

    for (const secret of ['SECRETTOKEN', 'a%40b.c', 'HASH', 'SIG', 'token=abc']) {
        assert.ok(!serialized.includes(secret), `wyciekło: ${secret}`);
    }
});

test('zdarzenie bez requestu przechodzi bez zmian', () => {
    assert.deepEqual(scrubEvent({ message: 'x' }), { message: 'x' });
});

test('breadcrumbs: nawigacja i XHR/fetch tracą tokeny i query string', () => {
    const navigation = scrubBreadcrumb({
        category: 'navigation',
        data: {
            from: '/login',
            to: '/reset-password/SECRETTOKEN?email=a%40b.c',
        },
    });
    const xhr = scrubBreadcrumb({
        category: 'xhr',
        data: { url: '/api/x?token=abc', method: 'GET', status_code: 500 },
    });

    assert.equal(navigation.data.to, '/reset-password/[Filtered]');
    assert.equal(navigation.data.from, '/login');
    assert.equal(xhr.data.url, '/api/x');
    assert.equal(xhr.data.status_code, 500);
});

test('breadcrumb bez data przechodzi bez zmian', () => {
    assert.deepEqual(scrubBreadcrumb({ category: 'ui.click', message: 'a' }), {
        category: 'ui.click',
        message: 'a',
    });
});
