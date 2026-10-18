<?php

use App\Metrics\CatalogGauges;
use Illuminate\Support\Facades\Log;

// Prometheus scrape'uje wewnętrzny port 2020 (middleware OnlyMetricsPort).
const METRICS_URL = 'http://catalog-app:2020/prometheus';

// Linia próbki to nazwa na początku wiersza i wartość; `# HELP`/`# TYPE` też
// zawierają nazwę, więc kotwiczymy wyrażenie na początku linii.
function hasSampleLine(string $body, string $metric): bool
{
    return preg_match('/^'.preg_quote($metric, '/').' \S+$/m', $body) === 1;
}

test('GET /prometheus oddaje gauge catalog', function () {
    $response = $this->get(METRICS_URL);

    $response->assertOk();
    $body = $response->getContent();

    expect($body)->toContain('catalog_outbox_pending 0')
        ->and($body)->toContain('# TYPE catalog_failed_jobs gauge')
        ->and($body)->toContain('catalog_metrics_scrape_errors 0')
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

    Log::spy();

    $response = $this->get(METRICS_URL);

    $response->assertOk();
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => $context['metric'] === 'failed_jobs')
        ->once();
    $body = $response->getContent();

    expect(hasSampleLine($body, 'catalog_outbox_pending'))->toBeTrue()
        ->and(hasSampleLine($body, 'catalog_failed_jobs'))->toBeFalse()
        // Zniknięta seria nie alarmuje sama, więc awaria jest policzona.
        ->and($body)->toContain('catalog_metrics_scrape_errors 1');
});

test('licznik błędów scrape nie kumuluje się między żądaniami', function () {
    $this->app->bind(CatalogGauges::class, fn () => new class
    {
        public function failedJobs(): ?float
        {
            throw new RuntimeException('baza padła');
        }

        public function __call(string $name, array $arguments): ?float
        {
            return 0.0;
        }
    });

    Log::spy();

    $first = $this->get(METRICS_URL)->getContent();
    $second = $this->get(METRICS_URL)->getContent();

    expect($first)->toContain('catalog_metrics_scrape_errors 1')
        ->and($second)->toContain('catalog_metrics_scrape_errors 1');
});

test('/prometheus odpowiada tylko na wewnętrznym porcie metryk', function (string $url, int $status) {
    $this->get($url)->assertStatus($status);
})->with([
    'port 2020' => ['http://catalog-app:2020/prometheus', 200],
    'port 80' => ['http://catalog-app/prometheus', 404],
    'port 443' => ['https://catalog.localhost/prometheus', 404],
]);

test('port metryk jest konfigurowalny', function () {
    config(['prometheus.internal_port' => 9100]);

    $this->get('http://catalog-app:2020/prometheus')->assertNotFound();
    $this->get('http://catalog-app:9100/prometheus')->assertOk();
});
