<?php

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Event;
use Tests\Support\FakeSentryTransport;
use Tests\TestCase;

/**
 * Przekroczenie limitu czasu zadania kończy się SIGALRM: worker oznacza zadanie jako
 * nieudane, wysyła JobTimedOut i od razu zabija proces. Nic nie woła handlera wyjątków,
 * więc bez tego listenera zadanie zabite przez timeout znika bez issue w GlitchTipie.
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

test('zadanie zabite przez timeout trafia do GlitchTipa', function () {
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\SlowSaga');

    Event::dispatch(new JobTimedOut('database', $job, 30));

    expect(FakeSentryTransport::$events)->toHaveCount(1);
    $exception = FakeSentryTransport::$events[0]->getExceptions()[0];
    expect($exception->getType())->toBe(TimeoutExceededException::class)
        ->and($exception->getValue())->toBe('App\\Jobs\\SlowSaga has timed out.');
});
