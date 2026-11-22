<?php

namespace App\Seller360\Rebuild;

use Psr\Log\LoggerInterface;

/**
 * Lista celów zapisu seller-360 (ETAP 13, przebudowa bez przestoju): pary `{main, public}`.
 *
 * Lista leży w Redisie (`seller360:write-targets`, JSON), bo pisarzy jest kilka procesów (projektor
 * `seller360-consumer`, harmonogram, synchronizacja rejestru, fan-out kuriera). Brak klucza = jedna para
 * aliasów zapisu. Przebudowa dopisuje parę nowych indeksów (`startDual`), a po przełączeniu aliasów wraca
 * do jednej pary (`finish`). Każdy proces czyta listę co najwyżej co sekundę (cache w pamięci), więc
 * zmiana dociera do wszystkich bez restartu — przebudowa po `startDual` czeka 2 s.
 *
 * Awaria Redisa: zostaje ostatnia znana lista (podwójny zapis nie może zniknąć po cichu na czas awarii),
 * a gdy listy jeszcze nie było — wyjątek (wiadomość wróci do retry). Uszkodzony JSON to też wyjątek,
 * nie cichy powrót do aliasów.
 */
class WriteTargets
{
    public const KEY = 'seller360:write-targets';

    private const TTL_SECONDS = 1.0;

    /** @var \Closure(): float */
    private readonly \Closure $now;

    /** @var list<array{main: string, public: string}>|null */
    private ?array $cached = null;

    private float $cachedAt = 0.0;

    /**
     * @param  (\Closure(): float)|null  $now  zegar w sekundach (podmieniany w testach)
     */
    public function __construct(
        private readonly \Redis $redis,
        private readonly LoggerInterface $logger,
        ?\Closure $now = null,
        private readonly string $key = self::KEY,
        private readonly string $defaultMain = 'seller-360-write',
        private readonly string $defaultPublic = 'seller-360-public-write',
    ) {
        $this->now = $now ?? static fn (): float => microtime(true);
    }

    /** @return list<array{main: string, public: string}> */
    public function current(): array
    {
        $now = ($this->now)();
        if ($this->cached !== null && $now - $this->cachedAt < self::TTL_SECONDS) {
            return $this->cached;
        }

        try {
            $raw = $this->redis->get($this->key);
        } catch (\RedisException $e) {
            if ($this->cached === null) {
                throw $e;
            }
            $this->logger->warning('Redis niedostępny, cele zapisu seller-360 z ostatniego odczytu.', ['error' => $e->getMessage()]);

            return $this->cached;
        }

        $this->cached = \is_string($raw) ? $this->decode($raw) : $this->defaults();
        $this->cachedAt = $now;

        return $this->cached;
    }

    /** Wszystkie indeksy i aliasy z listy (main i public każdej pary), bez powtórzeń. @return list<string> */
    public function indices(): array
    {
        $indices = [];
        foreach ($this->current() as $pair) {
            $indices[] = $pair['main'];
            $indices[] = $pair['public'];
        }

        return array_values(array_unique($indices));
    }

    /** Podwójny zapis: aliasy zapisu (stare indeksy) plus para nowych indeksów. */
    public function startDual(string $newMain, string $newPublic): void
    {
        $this->store([...$this->defaults(), ['main' => $newMain, 'public' => $newPublic]]);
    }

    /** Koniec podwójnego zapisu: jedna para (zwykle aliasy zapisu, już przepięte na nowe indeksy). */
    public function finish(string $main, string $public): void
    {
        $pairs = [['main' => $main, 'public' => $public]];
        if ($pairs === $this->defaults()) {
            $this->redis->del($this->key);
        } else {
            $this->redis->set($this->key, json_encode($pairs, \JSON_THROW_ON_ERROR));
        }
        $this->remember($pairs);
    }

    /** @param list<array{main: string, public: string}> $pairs */
    private function store(array $pairs): void
    {
        $this->redis->set($this->key, json_encode($pairs, \JSON_THROW_ON_ERROR));
        $this->remember($pairs);
    }

    /** @param list<array{main: string, public: string}> $pairs */
    private function remember(array $pairs): void
    {
        $this->cached = $pairs;
        $this->cachedAt = ($this->now)();
    }

    /** @return list<array{main: string, public: string}> */
    private function defaults(): array
    {
        return [['main' => $this->defaultMain, 'public' => $this->defaultPublic]];
    }

    /** @return list<array{main: string, public: string}> */
    private function decode(string $raw): array
    {
        $list = json_decode($raw, associative: true);
        if (! \is_array($list) || $list === [] || ! array_is_list($list)) {
            throw new \UnexpectedValueException("Uszkodzona lista celów zapisu w {$this->key}: {$raw}");
        }
        $pairs = [];
        foreach ($list as $pair) {
            if (! \is_array($pair) || ! \is_string($pair['main'] ?? null) || ! \is_string($pair['public'] ?? null)) {
                throw new \UnexpectedValueException("Uszkodzona lista celów zapisu w {$this->key}: {$raw}");
            }
            $pairs[] = ['main' => $pair['main'], 'public' => $pair['public']];
        }

        return $pairs;
    }
}
