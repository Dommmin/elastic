<?php

use App\Seller360\Simulation\QueueBackpressure;
use App\Seller360\Simulation\QueueBackpressureTimeout;

/**
 * Hamulec z wirtualnym zegarem: `sleep` przesuwa czas zamiast czekać, `depth()` oddaje zadaną sekwencję.
 *
 * @param  list<int>  $depths  kolejne głębokości (ostatnia powtarza się)
 */
function backpressure(array $depths, int $pollSeconds = 2): QueueBackpressure
{
    $clock = new stdClass;
    $clock->now = 0.0;
    $clock->sleeps = [];

    $bp = new class($depths, $clock, $pollSeconds) extends QueueBackpressure
    {
        /** @var list<string> */
        public array $asked = [];

        public function __construct(private array $depths, public stdClass $clock, int $pollSeconds)
        {
            parent::__construct(
                sleep: function (int $seconds) use ($clock): void {
                    $clock->sleeps[] = $seconds;
                    $clock->now += $seconds;
                },
                clock: fn (): float => $clock->now,
                pollSeconds: $pollSeconds,
            );
        }

        protected function depth(string $queue): int
        {
            $this->asked[] = $queue;

            return count($this->depths) > 1 ? array_shift($this->depths) : $this->depths[0];
        }
    };

    return $bp;
}

test('kolejka poniżej limitu: bez czekania', function () {
    $bp = backpressure([10]);

    expect($bp->waitBelow('q', 50_000))->toBe(0.0)
        ->and($bp->clock->sleeps)->toBe([]);
});

test('kolejka powyżej limitu: czeka, aż zejdzie poniżej, i zwraca czas czekania', function () {
    $bp = backpressure([60_000, 55_000, 40_000]);
    $messages = [];

    $waited = $bp->waitBelow('search.seller360', 50_000, onWait: function (string $line) use (&$messages): void {
        $messages[] = $line;
    });

    expect($waited)->toBe(4.0)
        ->and($bp->clock->sleeps)->toBe([2, 2])
        ->and($bp->asked)->toBe(['search.seller360', 'search.seller360', 'search.seller360'])
        ->and($messages)->toHaveCount(1)
        ->and($messages[0])->toContain('search.seller360')->toContain('60000');
});

test('wiadomości przed kolejką (outbox) liczą się do limitu', function () {
    $bp = backpressure([30_000]);
    $upstream = [25_000, 10_000];

    $waited = $bp->waitBelow('q', 50_000, function () use (&$upstream): int {
        return count($upstream) > 1 ? array_shift($upstream) : $upstream[0];
    });

    expect($waited)->toBe(2.0)
        ->and($bp->clock->sleeps)->toBe([2]);
});

test('przekroczony maksymalny czas czekania: wyjątek z instrukcją dla operatora', function () {
    $bp = backpressure([60_000], pollSeconds: 30);

    expect(fn () => $bp->waitBelow('search.seller360', 50_000, maxWaitSeconds: 900))
        ->toThrow(QueueBackpressureTimeout::class, 'search.seller360');

    expect($bp->clock->now)->toBeGreaterThanOrEqual(900.0)->toBeLessThan(960.0);
});
