<?php

namespace App\Command;

use App\Service\IndexAliases;
use Elastic\Elasticsearch\Client;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `php bin/console search:index:create [--index-version=N]` — tworzy fizyczny
 * indeks `products-vN` z `infra/elasticsearch/mappings/products-vN.json`
 * i dba o dwa aliasy: `products-search` (odczyt) i `products-write` (zapis).
 *
 * DLACZEGO mapowania i szablony tworzy KOD, nie klikanie w Kibanie
 * (docs/01-INFRASTRUKTURA.md, sekcja "Konwencje"): mapowanie w repo można
 * zrecenzować, wersjonować i odtworzyć identycznie na czystym klastrze.
 *
 * DLACZEGO aliasy, nie nazwa fizyczna wprost (D-11, docs/06-PLAN-WDROZENIA.md):
 * catalog czyta zawsze `products-search`, konsument pisze zawsze przez
 * `products-write`. Reindeks (ETAP 10) tworzy `products-vN+1` i przepina
 * aliasy osobno — kod, który czyta/pisze, nigdy się o tym nie dowie.
 *
 * Komenda NIGDY nie przepina istniejącego aliasu (to robi reindeks po bramce
 * jakości) — tylko dopina brakujący: na świeżym klastrze oba do `products-vN`,
 * na istniejącej instalacji z samym `products-search` dopina `products-write`
 * do tego samego indeksu.
 */
#[AsCommand(name: 'search:index:create', description: 'Tworzy indeks products-vN i dopina brakujące aliasy products-search / products-write')]
final class CreateSearchIndexCommand extends Command
{
    /** Bieżąca wersja mapowania — jedyne miejsce, które trzeba zmienić po wdrożeniu nowej. */
    public const CURRENT_VERSION = 1;

    /**
     * ŚCIEŻKA ABSOLUTNA, nie wędrówka `../../` od `%kernel.project_dir%`.
     * Kontener search-consumer widzi WYŁĄCZNIE `./apps/search` jako `/app` —
     * katalog `infra/` z korzenia repo w ogóle nie istnieje w jego systemie
     * plików. compose.yaml montuje `infra/elasticsearch/mappings` osobno,
     * dokładnie pod tę ścieżkę (patrz komentarz przy `search-consumer.volumes`).
     * Odkryte dopiero przy realnym uruchomieniu — na hosta `../../` "działało"
     * tylko dlatego, że lokalny filesystem ma tam repo, czego kontener nie ma.
     */
    private const MAPPINGS_DIR = '/infra/elasticsearch/mappings';

    /**
     * @param  string  $indexPrefix  prefiks nazwy indeksu (`products-v` → `products-v1`);
     *                               parametr dla testów na jednorazowych indeksach
     */
    public function __construct(
        private readonly Client $client,
        private readonly IndexAliases $aliases,
        private readonly string $indexPrefix = 'products-v',
        private readonly string $mappingsDir = self::MAPPINGS_DIR,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('index-version', null, InputOption::VALUE_REQUIRED, 'Numer wersji mapowania (products-vN.json)', (string) self::CURRENT_VERSION)
            ->addOption('force', null, null, 'Usuń i utwórz indeks od nowa, jeśli już istnieje (TYLKO dev, nie dla indeksu pod aliasem)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $version = (int) $input->getOption('index-version');
        if ($version < 1) {
            $io->error('--index-version musi być liczbą >= 1.');

            return Command::FAILURE;
        }

        $indexName = $this->indexPrefix.$version;
        $mappingPath = $this->mappingsDir.'/products-v'.$version.'.json';

        if (! is_file($mappingPath)) {
            $io->error("Brak pliku mapowania: {$mappingPath}");

            return Command::FAILURE;
        }

        $body = json_decode(file_get_contents($mappingPath), associative: true, flags: JSON_THROW_ON_ERROR);
        // "_meta" to dokumentacja dla ludzi (patrz sam plik) — ES przyjąłby ją
        // jako część mappings, ale nie ma tu po co jej wysyłać.
        unset($body['_meta']);

        $exists = $this->client->indices()->exists(['index' => $indexName])->asBool();

        if ($exists && $input->getOption('force')) {
            if (in_array($indexName, [$this->aliases->readTarget(), $this->aliases->writeTarget()], true)) {
                $io->error("--force: {$indexName} jest celem aliasu odczytu lub zapisu — odmawiam usunięcia.");

                return Command::FAILURE;
            }

            $io->warning('--force: usuwam istniejący indeks '.$indexName);
            $this->client->indices()->delete(['index' => $indexName]);
            $exists = false;
        }

        if ($exists) {
            $io->note($indexName.' już istnieje — pomijam tworzenie (użyj --force, żeby nadpisać).');
        } else {
            $this->client->indices()->create([
                'index' => $indexName,
                'body' => $body,
            ]);
            $io->success('Utworzono indeks '.$indexName);
        }

        $this->ensureAliases($io, $indexName);

        return Command::SUCCESS;
    }

    /**
     * Dopina TYLKO brakujące aliasy. Istniejącego nigdy nie przepina — nawet
     * jeśli wskazuje inny indeks niż ten, który właśnie utworzono (to robi
     * reindeks po bramce jakości, nie ta komenda).
     */
    private function ensureAliases(SymfonyStyle $io, string $indexName): void
    {
        $read = $this->aliases->readTarget();
        $write = $this->aliases->writeTarget();

        if ($read === null && $write === null) {
            $this->aliases->attach($this->aliases->read, $indexName);
            $this->aliases->attach($this->aliases->write, $indexName);
            $io->success('Aliasy '.$this->aliases->read.' i '.$this->aliases->write.' -> '.$indexName);

            return;
        }

        if ($write === null) {
            // Istniejąca instalacja z samym aliasem odczytu: zapis idzie do
            // tego samego indeksu, z którego czyta catalog.
            $this->aliases->attach($this->aliases->write, $read);
            $io->success('Dopięto '.$this->aliases->write.' -> '.$read);
        }

        if ($read === null) {
            $this->aliases->attach($this->aliases->read, $write);
            $io->success('Dopięto '.$this->aliases->read.' -> '.$write);
        }

        $io->note(sprintf('Aliasy: %s -> %s, %s -> %s.', $this->aliases->read, $this->aliases->readTarget(), $this->aliases->write, $this->aliases->writeTarget()));
    }
}
