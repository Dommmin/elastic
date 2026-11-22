<?php

namespace App\Seller360\Rebuild;

use App\Seller360\CatalogSellerClient;
use App\Seller360\Complaints\ComplaintsSectionRefresher;
use App\Seller360\OffersSectionFlusher;
use App\Seller360\RegionResolver;
use App\Seller360\Registry\RegistrySync;
use App\Seller360\SalesSectionRefresher;
use App\Seller360\SectionMeta;
use App\Seller360\SectionPayloads;
use App\Seller360\SectionWriter;
use Elastic\Elasticsearch\Client;
use Psr\Log\LoggerInterface;

/**
 * Przebudowa `seller-360` i `seller-360-public` bez przestoju (ETAP 13, Task 14).
 *
 *  1. `<alias>-v<N+1>` dla obu rodzin, z ustawieniami i mapowaniem z szablonu indeksu (jawnie w żądaniu,
 *     więc działa też dla nazw testowych, których wzorzec szablonu nie obejmuje);
 *  2. podwójny zapis: {@see WriteTargets::startDual()} i 2 s na propagację (procesy czytają listę co ≤ 1 s);
 *  3. backfill tylko do nowych indeksów (`SectionWriter::forPairs`), Z TYMI SAMYMI WERSJAMI co zapis na żywo —
 *     wyścig z podwójnym zapisem rozstrzyga strażnik wersji w skrypcie (starsze = noop), nie kolejność:
 *       - `profile` z `/api/internal/sellers` (`version` z catalogu; przy okazji `nip`, `region`, `synthetic`);
 *       - `billing` z `/api/internal/billing/accounts` (`version` konta);
 *       - `registry` z cache Redisa: `version` = `fetched_at` w ms (późniejsza synchronizacja na żywo ma większą
 *         wersję i wygrywa), `source_ts` = `fetched_at`, `ok` do {@see self::REGISTRY_OK_SECONDS}, potem `stale`;
 *         ocena kuriera nadpisana z `courier-ratings`. Brak cache albo stary format bez `fetched_at` (TTL 24 h,
 *         wpisy sprzed zmiany formatu) — sekcja przeniesiona ze starego indeksu z jej `_meta` (wersja bez zmian);
 *       - `offers`, `sales`, `complaints` przeliczone ich refresherami (wersja = chwila przeliczenia);
 *  4. weryfikacja (po refreshu): `_count` nowych nie mniejszy od starych (nadwyżka = sprzedawcy odzyskani
 *     z catalogu, raportowana) i próbka losowych `_id` porównana sekcja po sekcji, patrz {@see self::compare()};
 *     rozjazd ponawiamy do 3 razy co 2 s (zdarzenie w locie trafia do obu par z opóźnieniem kolejki);
 *  5. przełączenie czterech aliasów jednym `_aliases`, potem `finish` (z powrotem jedna para aliasów zapisu)
 *     i `_execute` polityki enrich (indeks enrich to migawka aliasu głównego);
 *  6. stare indeksy usuwane tylko z `deleteOld`.
 * Rozjazd ponad tolerancję: bez przełączenia, podwójny zapis zatrzymany, nowe indeksy zostają do oględzin.
 * Wyjątek w krokach 2–4 też zatrzymuje podwójny zapis. Druga przebudowa przy trwającym podwójnym zapisie odmawia.
 */
class Seller360Rebuilder
{
    /** Cache rejestru młodszy niż godzina (pełne przejście pętli rejestru to ~35 min) daje `ok`. */
    public const REGISTRY_OK_SECONDS = 3600;

    private const PAGE = 2000;
    private const WRITE_CHUNK = 500;
    private const SCAN_PAGE = 1000;
    private const PROPAGATION_MS = 2000;
    private const VERIFY_ATTEMPTS = 3;
    private const VERIFY_RETRY_MS = 2000;

    /** @var \Closure(int): void */
    private readonly \Closure $sleepMs;

    /**
     * @param  (\Closure(int): void)|null  $sleepMs  uśpienie w ms (podmieniane w testach)
     * @param  int|null  $replicas  nadpisanie replik z szablonu (testy na jednym węźle)
     */
    public function __construct(
        private readonly Client $es,
        private readonly SectionWriter $writer,
        private readonly WriteTargets $targets,
        private readonly CatalogSellerClient $catalog,
        private readonly RegionResolver $regions,
        private readonly OffersSectionFlusher $offers,
        private readonly SalesSectionRefresher $sales,
        private readonly ComplaintsSectionRefresher $complaints,
        private readonly \Redis $redis,
        private readonly LoggerInterface $logger,
        private readonly RebuildNames $names = new RebuildNames,
        ?\Closure $sleepMs = null,
        private readonly int $sampleSize = 500,
        private readonly int $tolerance = 0,
        private readonly ?int $replicas = null,
    ) {
        $this->sleepMs = $sleepMs ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
    }

    /**
     * @param  (\Closure(): void)|null  $afterDualStart  wołane między krokiem 2 a 3 (test wyścigu)
     * @param  (\Closure(string): void)|null  $progress  komunikaty postępu (komenda)
     */
    public function run(bool $deleteOld = false, ?\Closure $afterDualStart = null, ?\Closure $progress = null): RebuildReport
    {
        $say = $progress ?? static function (string $line): void {};
        if (\count($this->targets->current()) > 1) {
            throw new \RuntimeException('Podwójny zapis już trwa (przebudowa w toku albo przerwana): '.json_encode($this->targets->current()).'. Sprawdź i wyczyść '.WriteTargets::KEY.'.');
        }

        $oldMain = $this->aliasTarget($this->names->mainAlias);
        $oldPublic = $this->aliasTarget($this->names->publicAlias);
        $next = max($this->maxVersion($this->names->mainAlias), $this->maxVersion($this->names->publicAlias)) + 1;
        $report = new RebuildReport($oldMain, $oldPublic, "{$this->names->mainAlias}-v{$next}", "{$this->names->publicAlias}-v{$next}");

        $this->timed($report, 'create', function () use ($report): void {
            $this->createFromTemplate($report->newMain, $this->names->mainTemplate);
            $this->createFromTemplate($report->newPublic, $this->names->publicTemplate);
        });
        $say("1. utworzone {$report->newMain} i {$report->newPublic}");

        try {
            $this->timed($report, 'dual', function () use ($report): void {
                $this->targets->startDual($report->newMain, $report->newPublic);
                ($this->sleepMs)(self::PROPAGATION_MS);
            });
            $say('2. podwójny zapis włączony');
            if ($afterDualStart !== null) {
                $afterDualStart();
            }

            $this->timed($report, 'backfill', fn () => $this->backfill($report, $say));
            $say('3. backfill: '.json_encode($report->backfill));

            $this->timed($report, 'verify', fn () => $this->verify($report));
            $say(\sprintf('4. weryfikacja: _count stare %s, nowe %s; próbka %d, rozjazdów %d', json_encode($report->countsOld), json_encode($report->countsNew), $report->sampled, \count($report->mismatches)));

            if ($this->beyondTolerance($report)) {
                $this->stopDual();
                $report->status = 'aborted';
                $this->logger->error('Przebudowa seller-360 przerwana: rozjazd ponad tolerancję.', ['mismatches' => \array_slice($report->mismatches, 0, 20), 'counts_old' => $report->countsOld, 'counts_new' => $report->countsNew]);

                return $report;
            }

            // Switch też w bloku sprzątającym: błąd `_aliases` nie może zostawić podwójnego zapisu (RUNBOOK #147).
            $this->timed($report, 'switch', function () use ($report): void {
                $this->switchAliases($report);
                $this->stopDual();
            });
        } catch (\Throwable $e) {
            $this->stopDual();
            throw $e;
        }
        $report->status = 'switched';
        $say("5. aliasy przepięte na {$report->newMain} i {$report->newPublic}, podwójny zapis wyłączony");

        if ($this->names->enrichPolicy !== null) {
            $this->timed($report, 'enrich', function () use ($report): void {
                try {
                    $this->es->enrich()->executePolicy(['name' => $this->names->enrichPolicy, 'wait_for_completion' => true]);
                } catch (\Throwable $e) {
                    $report->enrichError = $e->getMessage();
                    $this->logger->warning('Nie udało się wykonać polityki enrich po przebudowie.', ['policy' => $this->names->enrichPolicy, 'error' => $e->getMessage()]);
                }
            });
            $say('   polityka enrich '.$this->names->enrichPolicy.': '.($report->enrichError ?? 'wykonana'));
        }

        if ($deleteOld) {
            $this->timed($report, 'delete_old', fn () => $this->es->indices()->delete(['index' => "{$oldMain},{$oldPublic}"]));
            $report->deletedOld = true;
            $say("6. usunięte {$oldMain} i {$oldPublic}");
        }

        return $report;
    }

    private function stopDual(): void
    {
        $this->targets->finish($this->names->mainWriteAlias(), $this->names->publicWriteAlias());
    }

    private function aliasTarget(string $alias): string
    {
        $indices = array_keys($this->es->indices()->getAlias(['name' => $alias])->asArray());
        if (\count($indices) !== 1) {
            throw new \RuntimeException("Alias {$alias} wskazuje ".\count($indices).' indeksów ('.implode(', ', $indices).'), oczekiwany jeden.');
        }

        return $indices[0];
    }

    private function maxVersion(string $alias): int
    {
        $rows = $this->es->cat()->indices(['index' => "{$alias}-v*", 'format' => 'json', 'h' => 'index', 'expand_wildcards' => 'all'])->asArray();
        $max = 0;
        foreach ($rows as $row) {
            if (preg_match('/^'.preg_quote($alias, '/').'-v(\d+)$/', (string) ($row['index'] ?? ''), $m) === 1) {
                $max = max($max, (int) $m[1]);
            }
        }

        return $max;
    }

    private function createFromTemplate(string $index, string $template): void
    {
        $definition = $this->es->indices()->getIndexTemplate(['name' => $template])->asArray()['index_templates'][0]['index_template']['template'] ?? null;
        if (! \is_array($definition)) {
            throw new \RuntimeException("Brak szablonu indeksu {$template} (make seller360-setup).");
        }
        $settings = $definition['settings'] ?? [];
        if ($this->replicas !== null) {
            unset($settings['index.number_of_replicas']);
            $settings['index']['number_of_replicas'] = $this->replicas;
        }
        $this->es->indices()->create(['index' => $index, 'body' => ['settings' => $settings, 'mappings' => $definition['mappings'] ?? []]]);
    }

    /** @param \Closure(string): void $say */
    private function backfill(RebuildReport $report, \Closure $say): void
    {
        $target = $this->writer->forPairs([['main' => $report->newMain, 'public' => $report->newPublic]]);
        $now = new \DateTimeImmutable;

        $catalogSellers = [];
        $this->timed($report, 'backfill_profile', function () use ($report, $target, $now, &$catalogSellers): void {
            $written = 0;
            $after = 0;
            do {
                $page = $this->catalog->sellers($after, self::PAGE);
                $items = [];
                foreach ($page['items'] as $row) {
                    $catalogSellers[(int) $row['seller_id']] = (bool) $row['synthetic'];
                    $profile = SectionPayloads::profile($row, $this->regions);
                    $items[] = ['sellerId' => (int) $row['seller_id'], 'section' => 'profile', 'data' => $profile['data'], 'meta' => new SectionMeta((int) $row['version'], $now), 'docFields' => $profile['doc']];
                }
                $written += $this->write($target, $items);
                $after = $page['next_after'];
            } while ($after !== null);
            $report->backfill['profile'] = $written;
        });

        $this->timed($report, 'backfill_billing', function () use ($report, $target, $now): void {
            $written = 0;
            $after = 0;
            do {
                $page = $this->catalog->billingAccounts($after, self::PAGE);
                $items = [];
                foreach ($page['items'] as $row) {
                    $items[] = ['sellerId' => (int) $row['seller_id'], 'section' => 'billing', 'data' => SectionPayloads::billing($row), 'meta' => new SectionMeta((int) $row['version'], $now), 'docFields' => []];
                }
                $written += $this->write($target, $items);
                $after = $page['next_after'];
            } while ($after !== null);
            $report->backfill['billing'] = $written;
        });

        // Jeden przebieg po starym indeksie: rejestr zapisywany stronami (bez trzymania 20 tys. sekcji w pamięci),
        // przy okazji lista tych, którzy mają `offers` i `complaints`.
        $old = ['offers' => [], 'complaints' => []];
        $this->timed($report, 'backfill_registry', function () use ($report, $target, &$old): void {
            $this->es->indices()->refresh(['index' => $report->oldMain]);
            $ratings = $this->ratings();
            $written = 0;
            $old = $this->scanOld($report->oldMain, function (array $rows) use ($target, $ratings, &$written): void {
                $written += $this->backfillRegistry($target, $rows, $ratings);
            });
            $report->backfill['registry'] = $written;
        });

        $this->timed($report, 'backfill_offers', function () use ($report, $target, $old, $catalogSellers): void {
            $real = array_keys(array_filter($catalogSellers, static fn (bool $synthetic): bool => ! $synthetic));
            $report->backfill['offers'] = $this->offers->refreshSellers(array_values(array_unique([...$old['offers'], ...$real])), $target);
        });

        $this->timed($report, 'backfill_sales', function () use ($report, $target): void {
            $report->backfill['sales'] = $this->sales->refresh(new \DateTimeImmutable, $target);
        });

        $this->timed($report, 'backfill_complaints', function () use ($report, $target, $old, $catalogSellers): void {
            $report->backfill['complaints'] = $this->complaints->refreshSellers(array_values(array_unique([...array_keys($catalogSellers), ...$old['complaints']])), null, $target);
        });
    }

    /**
     * Przebieg po starym indeksie głównym: kto ma `offers` i `complaints` (żeby nowy indeks miał co najmniej
     * te same sekcje), a strony sekcji `registry` z NIP-em idą do `$registryPage` (backfill rejestru i przeniesienie
     * sekcji bez cache).
     *
     * @param  \Closure(list<array{id: int, nip: ?string, section: array<string, mixed>}>): void  $registryPage
     * @return array{offers: list<int>, complaints: list<int>}
     */
    private function scanOld(string $index, \Closure $registryPage): array
    {
        $out = ['offers' => [], 'complaints' => []];
        $after = null;
        do {
            $body = [
                'size' => self::SCAN_PAGE,
                '_source' => ['seller_id', 'nip', 'registry', 'offers._meta.version', 'complaints._meta.version'],
                'sort' => [['seller_id' => 'asc']],
                'query' => ['match_all' => new \stdClass],
            ];
            if ($after !== null) {
                $body['search_after'] = $after;
            }
            $hits = $this->es->search(['index' => $index, 'body' => $body])->asArray()['hits']['hits'] ?? [];
            $registry = [];
            foreach ($hits as $hit) {
                $id = (int) $hit['_id'];
                $source = $hit['_source'] ?? [];
                if (isset($source['offers'])) {
                    $out['offers'][] = $id;
                }
                if (isset($source['complaints'])) {
                    $out['complaints'][] = $id;
                }
                if (isset($source['registry']) && \is_array($source['registry'])) {
                    $registry[] = ['id' => $id, 'nip' => \is_string($source['nip'] ?? null) ? $source['nip'] : null, 'section' => $source['registry']];
                }
            }
            if ($registry !== []) {
                $registryPage($registry);
            }
            $after = $hits !== [] ? ($hits[array_key_last($hits)]['sort'] ?? null) : null;
        } while ($hits !== [] && $after !== null);

        return $out;
    }

    /**
     * @param  list<array{id: int, nip: ?string, section: array<string, mixed>}>  $rows
     * @param  array<string, array{on_time_rate: ?float, rated_at: ?string}>  $ratings
     */
    private function backfillRegistry(SectionWriter $target, array $rows, array $ratings): int
    {
        $now = time();
        $written = 0;
        foreach (array_chunk($rows, self::WRITE_CHUNK) as $chunk) {
            $withNip = array_values(array_filter($chunk, static fn (array $r): bool => $r['nip'] !== null));
            $raw = $withNip === [] ? [] : $this->redis->mGet(array_map(static fn (array $r): string => RegistrySync::cacheKey($r['nip']), $withNip));
            $cache = [];
            foreach ($withNip as $i => $row) {
                $value = \is_array($raw) ? ($raw[$i] ?? false) : false;
                $cache[$row['id']] = \is_string($value) ? RegistrySync::decodeCache($value) : null;
            }

            $items = [];
            foreach ($chunk as $row) {
                $cached = $cache[$row['id']] ?? null;
                $docFields = $row['nip'] !== null ? ['nip' => $row['nip']] : [];
                if ($cached !== null && $cached[1] !== null) {
                    [$data, $fetchedAt] = $cached;
                    $courierId = $data['courier']['id'] ?? null;
                    if (\is_string($courierId) && isset($ratings[$courierId])) {
                        $data['courier'] = ['id' => $courierId] + $ratings[$courierId];
                    }
                    $fresh = $now - $fetchedAt <= self::REGISTRY_OK_SECONDS;
                    $meta = new SectionMeta($fetchedAt * 1000, (new \DateTimeImmutable)->setTimestamp($fetchedAt), $fresh ? 'ok' : 'stale', $fresh ? null : 'Przebudowa: dane z cache rejestru starsze niż '.self::REGISTRY_OK_SECONDS.' s.');
                } else {
                    // bez cache z chwilą pobrania: sekcja ze starego indeksu z jej własnym _meta
                    $data = $row['section'];
                    $old = \is_array($data['_meta'] ?? null) ? $data['_meta'] : [];
                    unset($data['_meta']);
                    if (! isset($old['version'], $old['source_ts'])) {
                        continue;
                    }
                    $meta = new SectionMeta((int) $old['version'], new \DateTimeImmutable((string) $old['source_ts']), (string) ($old['status'] ?? 'ok'), isset($old['error']) ? (string) $old['error'] : null);
                }
                $items[] = ['sellerId' => $row['id'], 'section' => 'registry', 'data' => $data, 'meta' => $meta, 'docFields' => $docFields];
            }
            $written += $this->write($target, $items);
        }

        return $written;
    }

    /** @return array<string, array{on_time_rate: ?float, rated_at: ?string}> oceny z `courier-ratings` po courier_id */
    private function ratings(): array
    {
        try {
            $hits = $this->es->search(['index' => $this->names->ratingsIndex, 'body' => ['size' => 1000, 'query' => ['match_all' => new \stdClass]]])->asArray()['hits']['hits'] ?? [];
        } catch (\Throwable $e) {
            $this->logger->warning('Brak ocen kurierów do backfillu rejestru, zostają oceny z cache.', ['error' => $e->getMessage()]);

            return [];
        }
        $ratings = [];
        foreach ($hits as $hit) {
            $source = $hit['_source'] ?? [];
            $ratings[(string) $hit['_id']] = [
                'on_time_rate' => isset($source['on_time_rate']) ? (float) $source['on_time_rate'] : null,
                'rated_at' => $source['rated_at'] ?? null,
            ];
        }

        return $ratings;
    }

    /**
     * @param  list<array{sellerId: int, section: string, data: array<string, mixed>, meta: SectionMeta, docFields: array<string, mixed>}>  $items
     * @return int zapisane (created|updated)
     */
    private function write(SectionWriter $target, array $items): int
    {
        $written = 0;
        foreach (array_chunk($items, self::WRITE_CHUNK) as $chunk) {
            foreach ($target->applyMany($chunk) as $i => $result) {
                if ($result === 'error') {
                    throw new \RuntimeException("Backfill: ES odrzucił zapis sekcji {$chunk[$i]['section']} sprzedawcy {$chunk[$i]['sellerId']}.");
                }
                if ($result !== 'noop') {
                    $written++;
                }
            }
        }

        return $written;
    }

    private function verify(RebuildReport $report): void
    {
        $this->es->indices()->refresh(['index' => implode(',', [$report->oldMain, $report->oldPublic, $report->newMain, $report->newPublic])]);
        $ids = $this->sample($report->oldMain);
        $report->sampled = \count($ids);
        for ($attempt = 1; $attempt <= self::VERIFY_ATTEMPTS; $attempt++) {
            if ($attempt > 1) {
                ($this->sleepMs)(self::VERIFY_RETRY_MS);
            }
            $this->es->indices()->refresh(['index' => implode(',', [$report->oldMain, $report->oldPublic, $report->newMain, $report->newPublic])]);
            $report->countsOld = ['main' => $this->count($report->oldMain), 'public' => $this->count($report->oldPublic)];
            $report->countsNew = ['main' => $this->count($report->newMain), 'public' => $this->count($report->newPublic)];

            $mismatches = $this->compare($report->oldMain, $report->newMain, $ids);
            $ids = array_keys($mismatches);
            $report->mismatches = array_values(array_map(static fn (int $id, string $why): string => "{$id}: {$why}", array_keys($mismatches), $mismatches));
            if (! $this->beyondTolerance($report)) {
                return;
            }
            // następna próba sprawdza tylko rozjechane `_id` (i zawsze liczby dokumentów)
        }
    }

    private function beyondTolerance(RebuildReport $report): bool
    {
        foreach (['main', 'public'] as $family) {
            if (($report->countsNew[$family] ?? 0) < ($report->countsOld[$family] ?? 0)) {
                return true;
            }
        }

        return \count($report->mismatches) > $this->tolerance;
    }

    private function count(string $index): int
    {
        return (int) $this->es->count(['index' => $index])->asArray()['count'];
    }

    /** @return list<int> losowe `_id` ze starego indeksu */
    private function sample(string $index): array
    {
        $hits = $this->es->search(['index' => $index, 'body' => [
            'size' => $this->sampleSize,
            '_source' => false,
            'query' => ['function_score' => ['query' => ['match_all' => new \stdClass], 'random_score' => ['seed' => random_int(1, \PHP_INT_MAX), 'field' => '_seq_no'], 'boost_mode' => 'replace']],
        ]])->asArray()['hits']['hits'] ?? [];

        return array_map(static fn (array $hit): int => (int) $hit['_id'], $hits);
    }

    /**
     * Porównanie stary vs nowy indeks główny dla podanych `_id` (gdy sekcja jest w starym):
     *  - dokument: musi być w nowym;
     *  - `profile`, `billing`: obecne i `_meta.version` RÓWNE (backfill pisze wersję źródła, podwójny zapis
     *    trafia do obu); sekcja tylko w nowym jest w porządku (odzyskana z catalogu, ruling R5);
     *  - `registry`: obecna i `source_ts` równe albo wersja nowego ≥ starej (backfill z cache ma wersję
     *    `fetched_at` w ms, mniejszą od wersji zapisu na żywo z tej samej synchronizacji, ale to samo `source_ts`);
     *  - `offers`, `sales`, `complaints`: tylko obecność — przeliczenie dostaje nową wersję (chwila przeliczenia);
     *  - `nip`: równy, gdy stary go miał.
     *
     * @param  list<int>  $ids
     * @return array<int, string> `_id` => opis rozjazdu
     */
    private function compare(string $oldIndex, string $newIndex, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $includes = ['nip', 'profile._meta', 'billing._meta', 'registry._meta', 'offers._meta.version', 'sales._meta.version', 'complaints._meta.version'];
        $old = $this->mget($oldIndex, $ids, $includes);
        $new = $this->mget($newIndex, $ids, $includes);

        $mismatches = [];
        foreach ($ids as $id) {
            $o = $old[$id] ?? null;
            if ($o === null) {
                continue; // dokument usunięty w międzyczasie (np. purge syntetycznych)
            }
            $n = $new[$id] ?? null;
            if ($n === null) {
                $mismatches[$id] = 'brak dokumentu w nowym indeksie';

                continue;
            }
            $why = [];
            foreach (['profile', 'billing'] as $section) {
                $ov = $o[$section]['_meta']['version'] ?? null;
                if ($ov !== null && ($n[$section]['_meta']['version'] ?? null) !== $ov) {
                    $why[] = "{$section} wersja stara {$ov}, nowa ".json_encode($n[$section]['_meta']['version'] ?? null);
                }
            }
            if (isset($o['registry']['_meta'])) {
                $om = $o['registry']['_meta'];
                $nm = $n['registry']['_meta'] ?? null;
                if ($nm === null) {
                    $why[] = 'registry brak w nowym';
                } elseif (($nm['source_ts'] ?? null) !== ($om['source_ts'] ?? null) && (int) ($nm['version'] ?? 0) < (int) ($om['version'] ?? 0)) {
                    $why[] = "registry source_ts stary {$om['source_ts']}, nowy {$nm['source_ts']}";
                }
            }
            foreach (['offers', 'sales', 'complaints'] as $section) {
                if (isset($o[$section]) && ! isset($n[$section])) {
                    $why[] = "{$section} brak w nowym";
                }
            }
            if (isset($o['nip']) && ($n['nip'] ?? null) !== $o['nip']) {
                $why[] = 'nip różny';
            }
            if ($why !== []) {
                $mismatches[$id] = implode('; ', $why);
            }
        }

        return $mismatches;
    }

    /**
     * @param  list<int>  $ids
     * @param  list<string>  $includes
     * @return array<int, array<string, mixed>>
     */
    private function mget(string $index, array $ids, array $includes): array
    {
        $docs = $this->es->mget(['index' => $index, '_source_includes' => implode(',', $includes), 'body' => ['ids' => array_map('strval', $ids)]])->asArray()['docs'] ?? [];
        $out = [];
        foreach ($docs as $doc) {
            if (($doc['found'] ?? false) === true) {
                $out[(int) $doc['_id']] = $doc['_source'] ?? [];
            }
        }

        return $out;
    }

    private function switchAliases(RebuildReport $report): void
    {
        $actions = [];
        foreach ([[$this->names->mainAlias, $report->oldMain, $report->newMain], [$this->names->publicAlias, $report->oldPublic, $report->newPublic]] as [$alias, $old, $new]) {
            $actions[] = ['remove' => ['index' => $old, 'alias' => $alias]];
            $actions[] = ['remove' => ['index' => $old, 'alias' => "{$alias}-write"]];
            $actions[] = ['add' => ['index' => $new, 'alias' => $alias]];
            $actions[] = ['add' => ['index' => $new, 'alias' => "{$alias}-write", 'is_write_index' => true]];
        }
        $this->es->indices()->updateAliases(['body' => ['actions' => $actions]]);
    }

    /** @param \Closure(): mixed $work */
    private function timed(RebuildReport $report, string $phase, \Closure $work): void
    {
        $started = hrtime(true);
        try {
            $work();
        } finally {
            $report->phasesMs[$phase] = (int) round((hrtime(true) - $started) / 1_000_000);
        }
    }
}
