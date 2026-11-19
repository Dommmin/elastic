<?php

namespace App\Service;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;

/**
 * Kroki migracji indeksu produktów bez przestoju (ETAP 10, spec 5.3–5.5, 8):
 *
 *   prepare → switch-write → reindex → finalize → switch-read   (+ abort, cleanup)
 *
 * Każdy krok sam sprawdza swoje warunki wstępne na STANIE KLASTRA (aliasy
 * i `_meta` nowego indeksu), a nie na tym, co pamięta proces — kroki można
 * więc uruchamiać osobnymi komendami, przerwać i wznowić. Odmowa to
 * MigrationRefused, zanim cokolwiek zostanie zmienione.
 *
 * Wersja N pochodzi zawsze z aliasów (`versionOf(readTarget())`), nigdy
 * z kodu: drugi przebieg po udanej migracji sam liczy vN+2.
 *
 * Metadane migracji żyją w `mappings._meta.migration` indeksu vN+1:
 *   source            — vN, z którego migrujemy (tylko taki vN+1 jest "nasz")
 *   replicas, refresh_interval — wartości z pliku mapowania, przywracane w finalize
 *   fill_method       — `reindex` albo `backfill`: ostatnia droga wypełnienia (wygrywa ostatnia)
 *   reindex_task      — id ostatniego zadania `_reindex` (jego wynik sprawdzają finalize/switch-read)
 *   backfill_completed_at, backfill_indexed/stale/not_found/deleted/errors — pieczęć udanego
 *                       `search:index:backfill` (droga B); finalize/switch-read przyjmują ją
 *                       zamiast zadania `_reindex`, ale tylko przy 0 błędów
 *   prepared_at, switch_write_at, switch_read_started_at, switched_read_at — ISO 8601 (UTC)
 */
final class IndexMigration
{
    public const DEFAULT_GC_DELETES = '1h';

    /** Pola pieczęci backfillu wyzerowane (putMeta usuwa wartości null). */
    private const BACKFILL_CLEARED = [
        'backfill_completed_at' => null,
        'backfill_indexed' => null,
        'backfill_stale' => null,
        'backfill_not_found' => null,
        'backfill_deleted' => null,
        'backfill_errors' => null,
    ];

    public function __construct(
        private readonly Client $client,
        private readonly IndexMapping $mappings,
        private readonly IndexGate $gate,
        private readonly float $pollSeconds = 2.0,
    ) {
    }

    /**
     * Krok 1–2: preflight i utworzenie vN+1 z 0 replik, bez odświeżania
     * (szybki zapis hurtowy) i z `gc_deletes` dłuższym niż migracja —
     * tombstone usunięcia ze strumienia musi przeżyć `_reindex` (spec 5.4,
     * DeleteDuringReindexTest). Zwraca nazwę celu.
     */
    public function prepare(IndexAliases $aliases, string $gcDeletes, bool $resume): string
    {
        $source = $this->idleIndex($aliases);

        $health = (string) $this->client->cluster()->health()->asArray()['status'];
        if ($health === 'red') {
            throw new MigrationRefused('Klaster jest czerwony — migracja nie startuje na niezdrowym klastrze.');
        }

        $target = self::nextIndex($source);
        $version = IndexAliases::versionOf($target);
        if (! $this->mappings->exists($version)) {
            throw new MigrationRefused("Brak pliku mapowania {$this->mappings->path($version)} — nie ma z czego zbudować {$target}.");
        }

        if ($this->exists($target)) {
            if (! $resume) {
                throw new MigrationRefused("{$target} już istnieje. Jeśli to cel przerwanej migracji z {$source}, uruchom prepare z --resume.");
            }
            $meta = $this->meta($target);
            if (($meta['source'] ?? null) !== $source) {
                throw new MigrationRefused("{$target} istnieje, ale nie utworzył go prepare migracji z {$source} — nie przejmuję obcego indeksu.");
            }
            if (isset($meta['switch_write_at'])) {
                // Po switch-write (i abort) vN+1 dostał zapisy strumienia, a usunięć
                // zrobionych w vN po abort nie zna — kopia z vN by ich nie usunęła.
                throw new MigrationRefused("{$target} był już celem zapisu (switch-write {$meta['switch_write_at']}) — nie wznawiam. Usuń {$target} i uruchom prepare od nowa.");
            }

            return $target;
        }

        $body = $this->mappings->load($version);
        $settings = $body['settings'] ?? [];
        $meta = [
            'source' => $source,
            'replicas' => (int) ($settings['number_of_replicas'] ?? $settings['index']['number_of_replicas'] ?? 1),
            'refresh_interval' => (string) ($settings['refresh_interval'] ?? $settings['index']['refresh_interval'] ?? '1s'),
            'prepared_at' => self::now(),
        ];
        unset($settings['index']['number_of_replicas'], $settings['index']['refresh_interval'], $settings['index']['gc_deletes']);
        $body['settings'] = ['number_of_replicas' => 0, 'refresh_interval' => '-1', 'gc_deletes' => $gcDeletes] + $settings;
        $body['mappings']['_meta']['migration'] = $meta;

        $this->client->indices()->create(['index' => $target, 'body' => $body]);

        return $target;
    }

    /**
     * Krok 3: alias zapisu → vN+1. Od teraz strumień pisze tylko do nowego
     * indeksu, a odczyt dalej z kompletnego vN. Czas przepięcia trafia do
     * `_meta` PRZED przepięciem — abort poda go jako początek reprojekcji
     * (wcześniejszy znacznik = bezpieczniej, wersje odrzucą duplikaty).
     *
     * @return array{target: string, already: bool}
     */
    public function switchWrite(IndexAliases $aliases): array
    {
        $read = $aliases->readTarget();
        $write = $aliases->writeTarget();

        if ($read !== null && $write !== null && $write !== $read) {
            [, $target] = $this->inProgress($aliases);

            return ['target' => $target, 'already' => true];
        }

        $source = $this->idleIndex($aliases);
        $target = self::nextIndex($source);
        if (! $this->exists($target) || ($this->meta($target)['source'] ?? null) !== $source) {
            throw new MigrationRefused("Brak {$target} utworzonego przez prepare z {$source} — najpierw `prepare`.");
        }

        $this->putMeta($target, ['switch_write_at' => self::now()]);
        $aliases->swap($aliases->write, $source, $target);

        return ['target' => $target, 'already' => false];
    }

    /**
     * Krok 4: `_reindex vN → vN+1` jako zadanie w tle. `version_type:
     * external` + `conflicts: proceed`: dokument, który strumień już zapisał
     * do vN+1 nowszą wersją (albo usunął — tombstone), wygrywa z kopią;
     * powtórzony `_reindex` po przerwaniu kopiuje tylko brakujące.
     *
     * @param int|string $slices `auto` albo liczba
     *
     * @return string identyfikator zadania (`node:id`)
     */
    public function startReindex(IndexAliases $aliases, int|string $slices, float $rps): string
    {
        [$source, $target] = $this->inProgress($aliases);
        $this->refuseWhileReindexRuns($target);

        // _reindex czyta przez search: zapisy sprzed przepięcia aliasu,
        // jeszcze nieodświeżone w vN, inaczej by się nie skopiowały.
        $this->client->indices()->refresh(['index' => $source]);

        $response = $this->client->reindex([
            'wait_for_completion' => false,
            'slices' => $slices,
            'requests_per_second' => $rps,
            'body' => [
                'conflicts' => 'proceed',
                'source' => ['index' => $source],
                'dest' => ['index' => $target, 'version_type' => 'external'],
            ],
        ])->asArray();
        $taskId = (string) $response['task'];

        // finalize i switch-read sprawdzają WYNIK tego zadania (Tasks API trzyma
        // go w `.tasks`, bo wait_for_completion=false) — nie tylko to, że nie trwa.
        // Ostatnia droga wygrywa: pieczęć wcześniejszego backfillu nie może
        // uwiarygodnić reindeksu, który właśnie wystartował.
        $this->putMeta($target, ['reindex_task' => $taskId, 'fill_method' => 'reindex'] + self::BACKFILL_CLEARED);

        return $taskId;
    }

    /**
     * Śledzi zadanie przez Tasks API do końca. `$shouldCancel()` sprawdzane
     * przy każdym odpytaniu (np. flaga ustawiana przez SIGINT) — wtedy
     * `_cancel` i czekanie, aż zadanie faktycznie się zatrzyma.
     *
     * @param callable(array<string, mixed>): void $onProgress dostaje `task.status`
     * @param callable(): bool                     $shouldCancel
     *
     * @return array{cancelled: bool, result: array<string, mixed>, error: ?array<string, mixed>}
     *                                     `result` = odpowiedź `_reindex` (created, updated, version_conflicts, total, failures…)
     */
    public function waitForTask(string $taskId, callable $onProgress, callable $shouldCancel): array
    {
        $cancelRequested = false;

        while (true) {
            $task = $this->client->tasks()->get(['task_id' => $taskId])->asArray();
            if ($task['completed'] ?? false) {
                $result = $task['response'] ?? $task['task']['status'] ?? [];

                return [
                    'cancelled' => $cancelRequested || ($result['canceled'] ?? null) !== null,
                    'result' => $result,
                    'error' => $task['error'] ?? null,
                ];
            }

            if (! $cancelRequested && $shouldCancel()) {
                try {
                    $this->client->tasks()->cancel(['task_id' => $taskId]);
                } catch (ClientResponseException $e) {
                    // Zadanie skończyło się między get a _cancel — następny get to pokaże.
                    if ($e->getCode() !== 404) {
                        throw $e;
                    }
                }
                $cancelRequested = true;

                continue;
            }

            $onProgress($task['task']['status'] ?? []);
            usleep((int) ($this->pollSeconds * 1_000_000));
        }
    }

    /**
     * Krok 5: repliki i `refresh_interval` z pliku mapowania (zapisane
     * w `_meta` w prepare), `_refresh`, zielony. Zwraca nazwę celu.
     */
    public function finalize(IndexAliases $aliases): string
    {
        [, $target] = $this->inProgress($aliases);
        $this->refuseUnlessFilled($target);
        $meta = $this->meta($target);

        $this->client->indices()->putSettings(['index' => $target, 'body' => ['index' => [
            'number_of_replicas' => $meta['replicas'],
            'refresh_interval' => $meta['refresh_interval'],
        ]]]);
        $this->client->indices()->refresh(['index' => $target]);

        $health = $this->gate->waitForGreen($target);
        if ($health !== 'green') {
            throw new MigrationRefused("{$target} nie zrobił się zielony (jest {$health}) — sprawdź alokację replik i powtórz finalize.");
        }

        return $target;
    }

    /**
     * Kroki 6–8: bramka, alias odczytu → vN+1, domyślne `gc_deletes`.
     * Bramka liczona tutaj, w tym samym procesie — nie ma flagi, która by
     * ją ominęła. Przy FAIL alias odczytu zostaje na vN (zwrócony wynik
     * mówi, co się nie zgadza).
     *
     * Wymaga udanego `_reindex` i finalize (repliki/refresh z mapowania) —
     * bramka sama odświeża indeks i przy 0 replik jest zielona, więc bez tego
     * odczyt mógłby trafić na indeks z `refresh_interval: -1`, w którym
     * zapisy strumienia nigdy nie stają się widoczne.
     *
     * `switch_read_started_at` trafia do `_meta` PRZED przepięciem: jeśli proces
     * padnie po nim, ponowne switch-read dokończy kroki po przepięciu
     * (zwraca wtedy null), a abort już odmawia.
     */
    public function switchRead(IndexAliases $aliases, float $tolerance): ?GateResult
    {
        $read = $aliases->readTarget();
        $meta = $read === null ? [] : $this->meta($read);
        if ($read !== null && $read === $aliases->writeTarget() && isset($meta['switch_read_started_at']) && ! isset($meta['switched_read_at'])) {
            $this->completeReadSwitch($read);

            return null;
        }

        [$source, $target] = $this->inProgress($aliases);
        $this->refuseUnlessFilled($target);
        $this->refuseUnlessFinalized($target);

        $result = $this->gate->check($target, $tolerance);
        if (! $result->passed()) {
            return $result;
        }

        $this->putMeta($target, ['switch_read_started_at' => self::now()]);
        $aliases->swap($aliases->read, $source, $target);
        $this->completeReadSwitch($target);

        return $result;
    }

    /**
     * Przerwanie migracji po switch-write (spec 8.1): oba aliasy z powrotem
     * na vN, vN+1 zostaje do diagnostyki. vN nie widział zapisów od
     * switch-write — zwracany czas to początek `search:reproject` do vN.
     *
     * @return array{source: string, target: string, since: string}
     */
    public function abort(IndexAliases $aliases): array
    {
        $write = $aliases->writeTarget();
        $meta = $write === null ? [] : $this->meta($write);
        $source = $meta['source'] ?? null;

        if ($write === null || $source === null || isset($meta['switch_read_started_at']) || ! isset($meta['switch_write_at'])) {
            throw new MigrationRefused(sprintf(
                'Nic do cofnięcia: alias zapisu %s wskazuje %s, który nie jest celem migracji w toku (po switch-write, przed switch-read).',
                $aliases->write,
                $write ?? '(brak)',
            ));
        }
        if (! $this->exists($source)) {
            throw new MigrationRefused("Indeks źródłowy {$source} nie istnieje — nie ma dokąd wrócić.");
        }
        $this->refuseWhileReindexRuns($write);

        $aliases->swap($aliases->write, $write, $source);
        if ($aliases->readTarget() === $write) {
            $aliases->swap($aliases->read, $write, $source);
        }

        return ['source' => $source, 'target' => $write, 'since' => (string) $meta['switch_write_at']];
    }

    /**
     * Indeksy tej samej rodziny (`<prefiks>-vK`), na które nie wskazuje żaden
     * z dwóch aliasów — kandydaci do `cleanup`. Pominięte (`skipped`): vN+1 po
     * prepare, jeszcze bez switch-write — to zaczęta migracja, nie śmieć.
     *
     * @return array{delete: list<string>, skipped: list<string>}
     */
    public function cleanupCandidates(IndexAliases $aliases): array
    {
        $read = $this->idleIndex($aliases);
        $prefix = (string) preg_replace('/\d+$/', '', $read);

        $rows = $this->client->cat()->indices(['index' => $prefix.'*', 'format' => 'json', 'h' => 'index'])->asArray();
        $names = array_map(static fn (array $row): string => (string) $row['index'], $rows);
        $names = array_filter($names, static fn (string $name): bool => preg_match('/^'.preg_quote($prefix, '/').'\d+$/', $name) === 1 && $name !== $read);
        sort($names);

        $result = ['delete' => [], 'skipped' => []];
        foreach ($names as $name) {
            $meta = $this->meta($name);
            $prepared = isset($meta['source']) && ! isset($meta['switch_write_at']);
            $result[$prepared ? 'skipped' : 'delete'][] = $name;
        }

        return $result;
    }

    /** Usuwa JEDEN indeks po jawnej nazwie, po ponownym sprawdzeniu, że nie jest celem aliasu. */
    public function deleteUnaliased(IndexAliases $aliases, string $index): void
    {
        if (\in_array($index, [$aliases->readTarget(), $aliases->writeTarget()], true)) {
            throw new MigrationRefused("{$index} jest celem aliasu odczytu lub zapisu — odmawiam usunięcia.");
        }

        $this->client->indices()->delete(['index' => $index]);
    }

    /** `products-v7` → `products-v8`. */
    public static function nextIndex(string $index): string
    {
        try {
            $version = IndexAliases::versionOf($index);
        } catch (\InvalidArgumentException) {
            throw new MigrationRefused("Alias wskazuje {$index} — nazwa bez przyrostka -vN, nie wiem, jaka jest następna wersja.");
        }

        return (string) preg_replace('/-v\d+$/', '-v'.($version + 1), $index);
    }

    /** Stan spoczynku: oba aliasy na tym samym indeksie. Zwraca ten indeks. */
    private function idleIndex(IndexAliases $aliases): string
    {
        $read = $aliases->readTarget();
        $write = $aliases->writeTarget();

        if ($read === null || $write === null || $read !== $write) {
            throw new MigrationRefused(sprintf(
                'Aliasy wskazują różne indeksy (%s → %s, %s → %s) — ten krok wymaga obu na tym samym vN.',
                $aliases->read, $read ?? '(brak)', $aliases->write, $write ?? '(brak)',
            ));
        }

        return $read;
    }

    /**
     * Migracja w toku: zapis na vN+1 utworzonym przez prepare z vN, odczyt
     * jeszcze na vN.
     *
     * @return array{0: string, 1: string} [vN, vN+1]
     */
    private function inProgress(IndexAliases $aliases): array
    {
        $read = $aliases->readTarget();
        $write = $aliases->writeTarget();

        if ($read === null || $write === null || $read === $write || ($this->meta($write)['source'] ?? null) !== $read) {
            throw new MigrationRefused(sprintf(
                'Brak migracji w toku (%s → %s, %s → %s) — ten krok wymaga aliasu zapisu na vN+1 z prepare i odczytu na vN; najpierw `switch-write`.',
                $aliases->read, $read ?? '(brak)', $aliases->write, $write ?? '(brak)',
            ));
        }

        return [$read, $write];
    }

    /**
     * Początek `search:index:backfill`: cel migracji traci stare pieczęcie
     * wypełnienia (zadanie `_reindex` i poprzedni backfill), więc częściowy
     * albo nieudany backfill nigdy nie przejdzie na cudzym wyniku.
     * Indeks bez migracji w `_meta` (np. stary vN przy abort) zostaje nietknięty.
     *
     * @return bool czy indeks jest celem migracji (ma `_meta.migration`)
     */
    public function beginBackfill(string $index): bool
    {
        if ($this->meta($index) === []) {
            return false;
        }
        $this->putMeta($index, ['reindex_task' => null, 'fill_method' => null] + self::BACKFILL_CLEARED);

        return true;
    }

    /**
     * Pieczęć udanego backfillu (tylko przy 0 błędów — wołający pilnuje).
     * Nie dotyka indeksów bez `_meta.migration`.
     *
     * @param array{indexed: int, stale: int, notfound: int, deleted: int, errors: int} $counts
     */
    public function completeBackfill(string $index, array $counts): void
    {
        if ($this->meta($index) === []) {
            return;
        }
        $this->putMeta($index, [
            'fill_method' => 'backfill',
            'backfill_completed_at' => self::now(),
            'backfill_indexed' => $counts['indexed'],
            'backfill_stale' => $counts['stale'],
            'backfill_not_found' => $counts['notfound'],
            'backfill_deleted' => $counts['deleted'],
            'backfill_errors' => $counts['errors'],
        ]);
    }

    /**
     * Cel jest wypełniony: udany backfill z `_meta` (0 błędów) ALBO udany
     * `_reindex`. Decyduje `fill_method` — ostatnia droga wygrywa.
     */
    private function refuseUnlessFilled(string $target): void
    {
        $meta = $this->meta($target);
        if (($meta['fill_method'] ?? null) === 'backfill') {
            if (! isset($meta['backfill_completed_at']) || (int) ($meta['backfill_errors'] ?? 1) !== 0) {
                throw new MigrationRefused("Backfill do {$target} nie zakończył się pomyślnie (brak pieczęci albo błędy) — uruchom `search:index:backfill` ponownie.");
            }

            return;
        }

        $this->refuseUnlessReindexSucceeded($target);
    }

    /** Zadanie z `_meta.reindex_task` skończone, bez błędu, bez `failures`, nieanulowane. */
    private function refuseUnlessReindexSucceeded(string $target): void
    {
        $taskId = $this->meta($target)['reindex_task'] ?? null;
        if ($taskId === null) {
            throw new MigrationRefused("Do {$target} nie uruchomiono jeszcze `reindex` (ani `search:index:backfill`).");
        }

        try {
            $task = $this->client->tasks()->get(['task_id' => $taskId])->asArray();
        } catch (ClientResponseException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }
            throw new MigrationRefused("Nie ma wyniku zadania {$taskId} w Tasks API — nie da się potwierdzić reindeksu. Uruchom `reindex` ponownie.");
        }

        if (! ($task['completed'] ?? false)) {
            throw new MigrationRefused("_reindex do {$target} jeszcze trwa (zadanie {$taskId}) — poczekaj (`reindex --wait` pokaże postęp) albo go anuluj.");
        }

        $response = $task['response'] ?? [];
        $problem = match (true) {
            isset($task['error']) => 'błąd: '.json_encode($task['error'], \JSON_UNESCAPED_UNICODE),
            ($response['canceled'] ?? null) !== null => 'anulowane ('.$response['canceled'].')',
            ($response['failures'] ?? []) !== [] => \count($response['failures']).' błędów zapisu, np. '.json_encode($response['failures'][0], \JSON_UNESCAPED_UNICODE),
            default => null,
        };
        if ($problem !== null) {
            throw new MigrationRefused("_reindex do {$target} (zadanie {$taskId}) nie zakończył się pomyślnie: {$problem}. Uruchom `reindex` ponownie — kopiuje tylko brakujące.");
        }
    }

    /** Repliki i refresh_interval takie jak w `_meta` (z pliku mapowania), czyli po finalize. */
    private function refuseUnlessFinalized(string $target): void
    {
        $meta = $this->meta($target);
        $settings = $this->client->indices()->getSettings(['index' => $target])->asArray()[$target]['settings']['index'];

        if ((string) ($settings['number_of_replicas'] ?? '') !== (string) $meta['replicas']
            || (string) ($settings['refresh_interval'] ?? '') !== (string) $meta['refresh_interval']) {
            throw new MigrationRefused(sprintf(
                '%s nie jest sfinalizowany (repliki %s, refresh_interval %s; oczekiwane %s, %s) — najpierw `finalize`.',
                $target, $settings['number_of_replicas'] ?? '?', $settings['refresh_interval'] ?? '?', $meta['replicas'], $meta['refresh_interval'],
            ));
        }
    }

    /** Kroki po przepięciu odczytu — idempotentne, więc można je powtórzyć po awarii. */
    private function completeReadSwitch(string $target): void
    {
        // null = usuń ustawienie, czyli wróć do domyślnych 60 s.
        $this->client->indices()->putSettings(['index' => $target, 'body' => ['index' => ['gc_deletes' => null]]]);
        $this->putMeta($target, ['switched_read_at' => self::now()]);
    }

    private function refuseWhileReindexRuns(string $target): void
    {
        $nodes = $this->client->tasks()->list(['actions' => 'indices:data/write/reindex', 'detailed' => true])->asArray()['nodes'] ?? [];
        foreach ($nodes as $node) {
            foreach ($node['tasks'] ?? [] as $id => $task) {
                if (str_contains((string) ($task['description'] ?? ''), "[{$target}]")) {
                    throw new MigrationRefused("_reindex do {$target} jeszcze trwa (zadanie {$id}) — poczekaj albo go anuluj.");
                }
            }
        }
    }

    private function exists(string $index): bool
    {
        return $this->client->indices()->exists(['index' => $index])->asBool();
    }

    /** @return array<string, mixed> `mappings._meta.migration` albo [] */
    private function meta(string $index): array
    {
        $mapping = $this->client->indices()->getMapping(['index' => $index])->asArray();

        return $mapping[$index]['mappings']['_meta']['migration'] ?? [];
    }

    /**
     * Dopisuje pola do `_meta.migration`. Put mapping ZASTĘPUJE całe `_meta`,
     * więc najpierw czytamy obecne.
     *
     * @param array<string, mixed> $fields
     */
    private function putMeta(string $index, array $fields): void
    {
        $mapping = $this->client->indices()->getMapping(['index' => $index])->asArray();
        $meta = $mapping[$index]['mappings']['_meta'] ?? [];
        // null w $fields = usuń pole (put mapping zastępuje całe `_meta`).
        $meta['migration'] = array_filter($fields + ($meta['migration'] ?? []), static fn ($v) => $v !== null);

        $this->client->indices()->putMapping(['index' => $index, 'body' => ['_meta' => $meta]]);
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DATE_ATOM);
    }
}
