<?php

namespace App\Command;

use App\Service\CatalogProjectionClient;
use App\Service\ElasticsearchIndexer;
use App\Service\IndexOutcome;
use App\Service\ProductSynchronizer;
use App\Service\ProjectionUnavailableException;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `php bin/console search:reproject` — przelicza KAŻDY dokument w aliasie
 * od nowa z projekcji catalogu (`make reproject`, na k3s `make k3s-reproject`).
 *
 * DLACZEGO istnieje: Panel [2/8] zmienił wersję dokumentu z `sequence`
 * zdarzenia (1, 2, 3…) na `projected_at_us` (~1.79e15). Produkty, które od
 * tamtej pory nie dostały żadnego zdarzenia, mają w ES nadal `_version` 1..3,
 * a droga zdarzenia w panelu (EventTrace) uznaje dokument za aktualny dopiero
 * przy `_version >= occurred_at` w µs — więc pokazuje "nie w ES" i polluje
 * bez końca. Jednorazowe przejście po wszystkich `_id` doprowadza wersje do
 * prawdy; ponowne uruchomienie jest bezpieczne (to te same zapisy, które
 * zrobiłby consumer, z tym samym external versioning).
 *
 * Tę samą decyzję (zapis albo usunięcie po 404) podejmuje ProductSynchronizer
 * co handler kolejki — dlatego komenda nie ma własnej logiki indeksowania.
 *
 * Działa równolegle z consumerem: wyścig rozstrzyga ES (starszy odczyt
 * dostaje 409 i jest liczony jako "nieaktualne", nie błąd).
 */
#[AsCommand(name: 'search:reproject', description: 'Przelicza wszystkie dokumenty w aliasie z projekcji catalogu (wersja = projected_at_us)')]
final class ReprojectCommand extends Command
{
    /** Ile błędów wypisać z identyfikatorem — reszta tylko w liczniku. */
    private const MAX_REPORTED_ERRORS = 20;

    public function __construct(
        private readonly Client $client,
        private readonly CatalogProjectionClient $catalogClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('alias', null, InputOption::VALUE_REQUIRED, 'Alias (albo indeks) do przeliczenia', 'products-search')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Ile _id na stronę scrolla', '500');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $alias = (string) $input->getOption('alias');
        $batch = max(1, (int) $input->getOption('batch'));

        // Indexer dla wskazanego aliasu — test pisze do jednorazowego indeksu,
        // produkcja zawsze do products-search.
        $synchronizer = new ProductSynchronizer($this->catalogClient, new ElasticsearchIndexer($this->client, $alias));

        $counts = ['indexed' => 0, 'stale' => 0, 'deleted' => 0, 'errors' => 0];

        foreach ($this->allIds($alias, $batch) as $productId) {
            try {
                $result = $synchronizer->sync($productId);
            } catch (ProjectionUnavailableException|ClientResponseException|ServerResponseException $e) {
                // Jeden niedostępny produkt nie może zatrzymać reszty — ale
                // nie może też zniknąć po cichu: licznik błędów daje kod ≠ 0,
                // a komendę można po prostu uruchomić jeszcze raz.
                if (++$counts['errors'] <= self::MAX_REPORTED_ERRORS) {
                    $io->warning("Produkt {$productId}: {$e->getMessage()}");
                }

                continue;
            }

            $key = match (true) {
                $result->outcome === IndexOutcome::Stale => 'stale',
                $result->deleted => 'deleted',
                default => 'indexed',
            };
            ++$counts[$key];
        }

        $summary = sprintf(
            'Zaindeksowano: %d, nieaktualne (409): %d, usunięte: %d, błędy: %d',
            $counts['indexed'],
            $counts['stale'],
            $counts['deleted'],
            $counts['errors'],
        );

        if ($counts['errors'] > 0) {
            $io->error($summary);

            return Command::FAILURE;
        }

        $io->success($summary);

        return Command::SUCCESS;
    }

    /**
     * Wszystkie `_id` w aliasie przez scroll: `_source: false` (potrzebny
     * tylko identyfikator — treść i tak przychodzi z catalogu), sort `_doc`
     * (najtańsza kolejność dla ES). Scroll to migawka z chwili startu, więc
     * zapisy i usunięcia w trakcie przejścia nie gubią ani nie dublują stron.
     *
     * @return \Generator<string>
     */
    private function allIds(string $alias, int $batch): \Generator
    {
        $response = $this->client->search([
            'index' => $alias,
            'scroll' => '2m',
            'size' => $batch,
            'body' => [
                '_source' => false,
                'sort' => ['_doc'],
                'query' => ['match_all' => new \stdClass()],
            ],
        ])->asArray();

        $scrollId = $response['_scroll_id'] ?? null;

        try {
            while (($hits = $response['hits']['hits'] ?? []) !== []) {
                foreach ($hits as $hit) {
                    yield (string) $hit['_id'];
                }

                $response = $this->client->scroll([
                    'body' => ['scroll_id' => $scrollId, 'scroll' => '2m'],
                ])->asArray();
                $scrollId = $response['_scroll_id'] ?? $scrollId;
            }
        } finally {
            // Kontekst scrolla trzyma zasoby w ES do wygaśnięcia — zwalniamy od razu.
            if ($scrollId !== null) {
                $this->client->clearScroll(['body' => ['scroll_id' => $scrollId]]);
            }
        }
    }
}
