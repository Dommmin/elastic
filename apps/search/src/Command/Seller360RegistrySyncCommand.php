<?php

namespace App\Command;

use App\Seller360\Registry\CircuitBreaker;
use App\Seller360\Registry\RegistrySync;
use Elastic\Elasticsearch\Client;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `seller360:registry:sync` — synchronizacja sekcji `registry` z zewnętrznym rejestrem (ETAP 13).
 *
 * Bez `--loop` jedno przejście; z `--seller=ID` tylko jeden sprzedawca. W pętli kolejność to
 * najstarsze `registry._meta.updated_at` pierwsze (sekcja bez daty na samym początku), a tempo to
 * najwyżej 20 żądań HTTP na sekundę (sprzedawca = 2 żądania). Gdy bezpiecznik jest otwarty,
 * nie ma HTTP, więc pętla nie hamuje: tylko odświeża sekcje stale/error z cache.
 * Osobny proces, bo awaria rejestru nie może zatrzymać kolejki zdarzeń.
 */
#[AsCommand(name: 'seller360:registry:sync', description: 'Synchronizuje sekcję registry seller-360 z rejestrem zewnętrznym')]
final class Seller360RegistrySyncCommand extends Command
{
    use CancelsOnSigint;

    private const REQUESTS_PER_SECOND = 20;
    private const REQUESTS_PER_SELLER = 2;
    private const PAGE_SIZE = 500;

    public function __construct(
        private readonly RegistrySync $sync,
        private readonly CircuitBreaker $breaker,
        private readonly Client $es,
        private readonly string $index = 'seller-360',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('seller', null, InputOption::VALUE_REQUIRED, 'Tylko ten sprzedawca (seller_id)')
            ->addOption('loop', null, InputOption::VALUE_NONE, 'Powtarzaj przejścia bez końca')
            ->addOption('pause', null, InputOption::VALUE_REQUIRED, 'Przerwa między przejściami w pętli (s)', '10');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $only = $input->getOption('seller');
        $loop = (bool) $input->getOption('loop');
        $pause = max(1, (int) $input->getOption('pause'));

        $this->withSigint(function () use ($output, $only, $loop, $pause): void {
            $previous = pcntl_signal_get_handler(\SIGTERM);
            pcntl_signal(\SIGTERM, function (): void {
                $this->interrupted = true;
            });
            try {
                do {
                    $done = $this->pass($only !== null ? (int) $only : null);
                    $output->writeln(\sprintf('seller360:registry:sync: przejście, sprzedawców: %d, bezpiecznik %s', $done, $this->breaker->isOpen() ? 'OTWARTY' : 'zamknięty'));
                    for ($i = 0; $loop && ! $this->interrupted && $i < $pause; $i++) {
                        sleep(1);
                    }
                } while ($loop && ! $this->interrupted);
            } finally {
                pcntl_signal(\SIGTERM, $previous);
            }
        });

        return Command::SUCCESS;
    }

    private function pass(?int $only): int
    {
        $sellers = $this->sellers($only);
        usort($sellers, static fn (array $a, array $b): int => $a['updated'] <=> $b['updated']);

        $done = 0;
        $minPerSeller = self::REQUESTS_PER_SELLER / self::REQUESTS_PER_SECOND;
        foreach ($sellers as $seller) {
            if ($this->interrupted) {
                break;
            }
            $started = microtime(true);
            $throttle = $this->breaker->allow();
            $this->sync->syncSeller($seller['id'], $seller['nip']);
            $done++;
            $left = $minPerSeller - (microtime(true) - $started);
            if ($throttle && $left > 0) {
                usleep((int) ($left * 1_000_000));
            }
        }

        return $done;
    }

    /** @return list<array{id: int, nip: string, updated: string}> sprzedawcy z NIP-em, `search_after` po seller_id */
    private function sellers(?int $only): array
    {
        $sellers = [];
        $after = null;
        do {
            $body = [
                'size' => self::PAGE_SIZE,
                '_source' => ['seller_id', 'nip', 'registry._meta.updated_at'],
                'sort' => [['seller_id' => 'asc']],
                'query' => $only !== null ? ['term' => ['seller_id' => $only]] : ['exists' => ['field' => 'nip']],
            ];
            if ($after !== null) {
                $body['search_after'] = $after;
            }
            $hits = $this->es->search(['index' => $this->index, 'body' => $body])->asArray()['hits']['hits'] ?? [];
            foreach ($hits as $hit) {
                $source = $hit['_source'] ?? [];
                $nip = $source['nip'] ?? null;
                if (! \is_string($nip) || $nip === '' || ! isset($source['seller_id'])) {
                    continue;
                }
                $sellers[] = ['id' => (int) $source['seller_id'], 'nip' => $nip, 'updated' => (string) ($source['registry']['_meta']['updated_at'] ?? '')];
            }
            $after = $hits !== [] ? ($hits[array_key_last($hits)]['sort'] ?? null) : null;
        } while ($hits !== [] && $after !== null && $only === null);

        return $sellers;
    }
}
