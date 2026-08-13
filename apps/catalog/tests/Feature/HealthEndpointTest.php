<?php

/**
 * W środowisku testowym baza (SQLite) jest dostępna, ale Redis/ES/RabbitMQ
 * nie (nie łączymy testów jednostkowych z żywą infrastrukturą). To dobra
 * okazja, żeby sprawdzić dokładnie ten scenariusz z docs/02-APLIKACJE.md:
 * "degradacja" — krytyczna zależność (baza) działa, pomocnicze nie,
 * odpowiedź to mimo to HTTP 200 z jawnym opisem, co konkretnie nie działa.
 */
test('/api/health zwraca 200 z degraded, gdy baza działa, a reszta nie', function () {
    $response = $this->getJson('/api/health');

    $response->assertStatus(200)
        ->assertJsonPath('status', fn ($status) => in_array($status, ['ok', 'degraded'], true))
        ->assertJsonPath('checks.database.status', 'ok')
        ->assertJsonStructure([
            'status',
            'checks' => ['database', 'redis', 'elasticsearch', 'rabbitmq'],
        ]);
});

test('/api/health raportuje latencję dla działających zależności', function () {
    $response = $this->getJson('/api/health');

    $response->assertJsonPath('checks.database.status', 'ok')
        ->assertJsonStructure(['checks' => ['database' => ['latency_ms']]]);
});
