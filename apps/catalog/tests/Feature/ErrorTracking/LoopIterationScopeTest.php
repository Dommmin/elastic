<?php

use App\Support\ErrorTracking\LoopIterationScope;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\SentrySdk;
use Tests\Support\FakeSentryTransport;
use Tests\TestCase;

/**
 * Długo żyjące pętle (`outbox:publish --loop`) nie mają granicy żądania ani wiadomości,
 * po której SDK czyściłby scope. Bez ręcznego czyszczenia breadcrumbs (SQL co sekundę z
 * pustego outboxu) zapełniają limit i wypychają kontekst prawdziwej partii.
 */
beforeAll(function () {
    TestCase::$sentryDsn = 'http://00000000000000000000000000000001@glitchtip/1';
});

afterAll(function () {
    TestCase::$sentryDsn = null;
});

beforeEach(function () {
    FakeSentryTransport::reset();
});

test('breadcrumbs z poprzedniej iteracji nie trafiają do błędu z następnej', function () {
    SentrySdk::getCurrentHub()->addBreadcrumb(new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'db.sql.query', 'select * from outbox (pusty przebieg)'));

    LoopIterationScope::reset();
    SentrySdk::getCurrentHub()->addBreadcrumb(new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'outbox', 'partia 42'));
    SentrySdk::getCurrentHub()->captureException(new RuntimeException('publikacja padła'));

    /** @var Event $event */
    $event = FakeSentryTransport::$events[0];
    $messages = array_map(fn (Breadcrumb $b) => $b->getMessage(), $event->getBreadcrumbs());

    expect($messages)->toBe(['partia 42']);
});

test('czyszczenie nie rzuca, gdy SDK jest wyłączone', function () {
    TestCase::$sentryDsn = null;

    expect(LoopIterationScope::reset())->toBeNull();
});
