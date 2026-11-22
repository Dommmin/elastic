<?php

namespace App\Seller360\Registry;

use App\Seller360\Seller360Job;
use Elastic\Elasticsearch\Client;
use Psr\Log\LoggerInterface;

/**
 * Fan-out ocen kuriera (ETAP 13b): zmiana oceny w rejestrze rozchodzi się na wszystkie dokumenty sprzedawców
 * przypisanych do tego kuriera (~2,5 tys. z 20 tys. na kuriera).
 *
 * Przebieg `poll($since)`:
 *   1. `GET /couriers/ratings?since=` z rejestru (tylko oceny zmienione po `since`);
 *   2. zapis oceny do `courier-ratings` (`_id = courier_id`), skąd czyta ją RegistrySync przy pełnej synchronizacji;
 *   3. dla każdego kuriera i indeksu docelowego `_update_by_query` (`term registry.courier.id`) ze skryptem
 *      `seller360-courier-rating`: noop, gdy zapisane `rated_at` jest nie starsze od przychodzącego (porównanie
 *      w ms, więc kolejność nadejścia i format strefy nie mają znaczenia), inaczej ustawia `on_time_rate` i `rated_at`;
 *      `_meta` sekcji zostaje nietknięte (fan-out to nie synchronizacja rejestru);
 *   4. `slices: auto`, `conflicts: proceed`, `wait_for_completion: false` i odpytywanie `GET _tasks/<id>` co 1 s.
 * `conflicts: proceed` jest bezpieczne: konflikt oznacza, że dokument właśnie zapisał inny pisarz (np. RegistrySync
 * z własną, nie starszą oceną z `courier-ratings`), a kolejny przebieg i tak zastosuje strażnika.
 *
 * Jako zadanie harmonogramu (`courier:poll`, 60 s) trzyma `since` w Redisie `seller360:courier:since` i przesuwa
 * je na największe `rated_at` dopiero po udanym fan-oucie; wyjątek zostawia `since` bez zmian, więc następny
 * przebieg powtarza pracę (skrypt jest idempotentny).
 */
class CourierFanOut implements Seller360Job
{
    public const SINCE_KEY = 'seller360:courier:since';
    public const SCRIPT_ID = 'seller360-courier-rating';

    /** Zapas na najdłuższy dopuszczalny fan-out jednego przebiegu. */
    private const MAX_WAIT_SECONDS = 300;

    private const TASK_POLL_MS = 1000;

    /** @var \Closure(int): void */
    private readonly \Closure $sleepMs;

    /**
     * @param  (\Closure(int): void)|null  $sleepMs  uśpienie w ms między odczytami zadania (podmieniane w testach)
     * @param  list<string>|null  $targets  indeksy lub aliasy do aktualizacji; null = `writeTargets()` (Task 14 podmieni to na WriteTargets)
     */
    public function __construct(
        private readonly RegistryClient $registry,
        private readonly Client $es,
        private readonly \Redis $redis,
        private readonly LoggerInterface $logger,
        ?\Closure $sleepMs = null,
        private readonly string $ratingsIndex = 'courier-ratings',
        private readonly ?array $targets = null,
    ) {
        $this->sleepMs = $sleepMs ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
    }

    public function name(): string
    {
        return 'courier:poll';
    }

    public function intervalSeconds(): int
    {
        return 60;
    }

    public function run(): void
    {
        $this->pollFromRedis();
    }

    /** Jeden przebieg z `since` z Redisa; zwraca wynik, a `since` przesuwa po sukcesie. */
    public function pollFromRedis(): FanOutResult
    {
        $stored = $this->redis->get(self::SINCE_KEY);
        $since = \is_string($stored) && $stored !== ''
            ? new \DateTimeImmutable($stored)
            : new \DateTimeImmutable('@0');

        $result = $this->poll($since);
        if ($result->maxRatedAt !== null) {
            $this->redis->set(self::SINCE_KEY, $result->maxRatedAt);
            $this->logger->info('Fan-out ocen kuriera.', ['couriers' => $result->couriers, 'updated' => $result->updated, 'noops' => $result->noops, 'conflicts' => $result->conflicts, 'took_ms' => $result->tookMs]);
        }

        return $result;
    }

    public function poll(\DateTimeImmutable $since): FanOutResult
    {
        $ratings = $this->registry->ratings($since->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'));
        if ($ratings === []) {
            return new FanOutResult(0, 0, 0, 0, 0);
        }

        $started = hrtime(true);
        $maxRatedAt = null;
        $maxMs = null;
        $tasks = [];
        foreach ($ratings as $rating) {
            $ratedMs = (int) (new \DateTimeImmutable($rating['rated_at']))->format('Uv');
            if ($maxMs === null || $ratedMs > $maxMs) {
                $maxMs = $ratedMs;
                $maxRatedAt = $rating['rated_at'];
            }
            $this->es->index([
                'index' => $this->ratingsIndex,
                'id' => $rating['courier_id'],
                'body' => ['courier_id' => $rating['courier_id'], 'on_time_rate' => $rating['on_time_rate'], 'rated_at' => $rating['rated_at']],
            ]);
            foreach ($this->writeTargets() as $target) {
                $tasks[] = $this->start($target, $rating, $ratedMs);
            }
        }

        $updated = $noops = $conflicts = 0;
        foreach ($tasks as $taskId) {
            $response = $this->await($taskId);
            $updated += (int) ($response['updated'] ?? 0);
            $noops += (int) ($response['noops'] ?? 0);
            $conflicts += (int) ($response['version_conflicts'] ?? 0);
        }

        return new FanOutResult(\count($ratings), $updated, $noops, $conflicts, (int) round((hrtime(true) - $started) / 1_000_000), $maxRatedAt);
    }

    /** Jedyne miejsce z listą celów (Task 14: iteracja po WriteTargets). */
    private function writeTargets(): array
    {
        return $this->targets ?? ['seller-360-write', 'seller-360-public-write'];
    }

    /**
     * @param  array{courier_id: string, on_time_rate: float, rated_at: string}  $rating
     */
    private function start(string $target, array $rating, int $ratedMs): string
    {
        $response = $this->es->updateByQuery([
            'index' => $target,
            'slices' => 'auto',
            'conflicts' => 'proceed',
            'wait_for_completion' => 'false',
            'refresh' => 'true',
            'body' => [
                'query' => ['term' => ['registry.courier.id' => $rating['courier_id']]],
                'script' => [
                    'id' => self::SCRIPT_ID,
                    'params' => ['rate' => $rating['on_time_rate'], 'rated_at' => $rating['rated_at'], 'rated_at_ms' => $ratedMs],
                ],
            ],
        ])->asArray();

        $task = $response['task'] ?? null;
        if (! \is_string($task)) {
            throw new \RuntimeException('_update_by_query nie zwrócił identyfikatora zadania.');
        }

        return $task;
    }

    /** @return array<string, mixed> `response` ukończonego zadania */
    private function await(string $taskId): array
    {
        $deadline = hrtime(true) + self::MAX_WAIT_SECONDS * 1_000_000_000;
        while (true) {
            $task = $this->es->tasks()->get(['task_id' => $taskId])->asArray();
            if (($task['completed'] ?? false) === true) {
                if (isset($task['error'])) {
                    throw new \RuntimeException('Zadanie fan-outu zakończone błędem: '.json_encode($task['error'], \JSON_UNESCAPED_UNICODE));
                }

                return $task['response'] ?? [];
            }
            if (hrtime(true) > $deadline) {
                throw new \RuntimeException(\sprintf('Fan-out nie skończył się w %d s (zadanie %s).', self::MAX_WAIT_SECONDS, $taskId));
            }
            ($this->sleepMs)(self::TASK_POLL_MS);
        }
    }
}
