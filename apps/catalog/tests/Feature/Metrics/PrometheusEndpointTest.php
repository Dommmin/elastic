<?php

use App\Metrics\CatalogGauges;

// Linia próbki to nazwa na początku wiersza i wartość; `# HELP`/`# TYPE` też
// zawierają nazwę, więc kotwiczymy wyrażenie na początku linii.
function hasSampleLine(string $body, string $metric): bool
{
    return preg_match('/^'.preg_quote($metric, '/').' \S+$/m', $body) === 1;
}

test('GET /prometheus oddaje gauge catalog', function () {
    $response = $this->get('/prometheus');

    $response->assertOk();
    $body = $response->getContent();

    expect($body)->toContain('catalog_outbox_pending 0')
        ->and($body)->toContain('# TYPE catalog_failed_jobs gauge')
        // Sweep jeszcze nie biegł: metryka zadeklarowana, ale bez próbki.
        ->and(hasSampleLine($body, 'catalog_ordering_sweep_last_run_age_seconds'))->toBeFalse();
});

test('gauge, który rzuca, nie psuje endpointu', function () {
    // CatalogGauges jest `final`, więc dubler to osobna klasa podstawiona pod
    // jej nazwę w kontenerze (rejestracja woła metody dynamicznie, bez typu).
    $this->app->bind(CatalogGauges::class, fn () => new class
    {
        public function failedJobs(): ?float
        {
            throw new RuntimeException('baza padła');
        }

        public function __call(string $name, array $arguments): ?float
        {
            return $name === 'orderingSweepLastRunAgeSeconds' ? null : 0.0;
        }
    });

    $response = $this->get('/prometheus');

    $response->assertOk();
    $body = $response->getContent();

    expect(hasSampleLine($body, 'catalog_outbox_pending'))->toBeTrue()
        ->and(hasSampleLine($body, 'catalog_failed_jobs'))->toBeFalse();
});
