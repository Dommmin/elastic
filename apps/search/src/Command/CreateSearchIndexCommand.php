<?php

namespace App\Command;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `php bin/console search:index:create` — tworzy fizyczny indeks
 * `products-v1` z mapowania w infra/elasticsearch/mappings/products-v1.json
 * i podpina pod niego alias `products-search`.
 *
 * DLACZEGO mapowania i szablony tworzy KOD, nie klikanie w Kibanie
 * (docs/01-INFRASTRUKTURA.md, sekcja "Konwencje"): mapowanie w repo można
 * zrecenzować, wersjonować i odtworzyć identycznie na czystym klastrze.
 *
 * DLACZEGO alias, nie nazwa fizyczna wprost (D-11, docs/06-PLAN-WDROZENIA.md):
 * `ProductSearchService` (ETAP 7) i `ElasticsearchIndexer` (już napisane)
 * mówią zawsze do `products-search`. Reindeks w ETAPIE 10 stworzy
 * `products-v2` i atomowo przepnie alias — kod, który czyta/pisze, nigdy
 * się o tym nie dowie.
 */
#[AsCommand(name: 'search:index:create', description: 'Tworzy indeks products-v1 i alias products-search')]
final class CreateSearchIndexCommand extends Command
{
    private const INDEX_NAME = 'products-v1';

    private const ALIAS_NAME = 'products-search';

    public function __construct(
        private readonly Client $client,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, null, 'Usuń i utwórz indeks od nowa, jeśli już istnieje (TYLKO dev!)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $mappingPath = $this->projectDir.'/../../infra/elasticsearch/mappings/'.self::INDEX_NAME.'.json';

        if (! is_file($mappingPath)) {
            $io->error("Brak pliku mapowania: {$mappingPath}");

            return Command::FAILURE;
        }

        $body = json_decode(file_get_contents($mappingPath), associative: true, flags: JSON_THROW_ON_ERROR);
        // "_meta" to dokumentacja dla ludzi (patrz sam plik) — ES przyjąłby ją
        // jako część mappings, ale nie ma tu po co jej wysyłać.
        unset($body['_meta']);

        $exists = $this->client->indices()->exists(['index' => self::INDEX_NAME])->asBool();

        if ($exists && $input->getOption('force')) {
            $io->warning('--force: usuwam istniejący indeks '.self::INDEX_NAME);
            $this->client->indices()->delete(['index' => self::INDEX_NAME]);
            $exists = false;
        }

        if ($exists) {
            $io->note(self::INDEX_NAME.' już istnieje — pomijam tworzenie (użyj --force, żeby nadpisać).');
        } else {
            $this->client->indices()->create([
                'index' => self::INDEX_NAME,
                'body' => $body,
            ]);
            $io->success('Utworzono indeks '.self::INDEX_NAME);
        }

        $this->ensureAlias($io);

        return Command::SUCCESS;
    }

    private function ensureAlias(SymfonyStyle $io): void
    {
        try {
            $currentIndices = $this->client->indices()
                ->getAlias(['name' => self::ALIAS_NAME])
                ->asArray();
        } catch (ClientResponseException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }
            $currentIndices = [];
        }

        if (array_key_exists(self::INDEX_NAME, $currentIndices)) {
            $io->note('Alias '.self::ALIAS_NAME.' już wskazuje na '.self::INDEX_NAME.'.');

            return;
        }

        // Atomowa zamiana: remove starych powiązań + add nowego w JEDNYM
        // wywołaniu _aliases. Gdyby zrobić to jako dwa osobne zapytania,
        // byłoby okno, w którym alias nie wskazuje na NIC — dokładnie ten
        // problem, którego unikamy w ETAPIE 10 przy reindeksie.
        $actions = [];
        foreach (array_keys($currentIndices) as $oldIndex) {
            $actions[] = ['remove' => ['index' => $oldIndex, 'alias' => self::ALIAS_NAME]];
        }
        $actions[] = ['add' => ['index' => self::INDEX_NAME, 'alias' => self::ALIAS_NAME]];

        $this->client->indices()->updateAliases(['body' => ['actions' => $actions]]);

        $io->success('Alias '.self::ALIAS_NAME.' -> '.self::INDEX_NAME);
    }
}
