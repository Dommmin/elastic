<?php

namespace App\Tests\Integration\Command;

use App\Command\IndexGateCommand;
use App\Service\IndexGate;
use App\Tests\Support\RealElasticsearch;
use Elastic\Elasticsearch\Client;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `search:index:gate` (spec ETAP 10, 5.5) na prawdziwym ES: indeks zielony
 * po `_refresh` i liczba dokumentów zgodna z catalogiem w tolerancji.
 * Catalog jest fałszywy (MockHttpClient) — liczy się tylko jego odpowiedź.
 */
#[Group('integration')]
final class IndexGateCommandTest extends TestCase
{
    use RealElasticsearch;

    private Client $client;
    private string $index;
    private int $catalogCount = 2000;

    protected function setUp(): void
    {
        $this->client = $this->elasticsearchOrSkip();
        $this->index = 'products-gate-'.uniqid();
        $this->client->indices()->create(['index' => $this->index, 'body' => $this->mappingBody(1)]);
    }

    protected function tearDown(): void
    {
        if (isset($this->index)) {
            $this->client->indices()->delete(['index' => $this->index, 'ignore_unavailable' => true]);
        }
    }

    public function test_indeks_z_30_procent_dokumentow_nie_przechodzi_z_regula_liczby(): void
    {
        $this->bulkProducts($this->client, $this->index, 1, 600);

        $tester = $this->gate();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('GATE=FAIL', $tester->getDisplay());
        self::assertMatchesRegularExpression('/liczba.*600.*2000/i', $tester->getDisplay());
    }

    public function test_pelny_indeks_przechodzi(): void
    {
        $this->bulkProducts($this->client, $this->index, 1, 2000);

        $tester = $this->gate();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('GATE=PASS', $tester->getDisplay());
        self::assertStringNotContainsString('GATE=FAIL', $tester->getDisplay());
    }

    public function test_roznica_w_tolerancji_przechodzi_a_ponad_nia_nie(): void
    {
        // 1 brakujący z 2000 = 0,0005 ≤ 0,001 (zdarzenie w locie).
        $this->bulkProducts($this->client, $this->index, 1, 1999);
        self::assertStringContainsString('GATE=PASS', $this->gate()->getDisplay());

        // Ta sama różnica przy tolerancji 0 to już odmowa.
        self::assertStringContainsString('GATE=FAIL', $this->gate(['--tolerance' => '0'])->getDisplay());
    }

    public function test_bramka_sama_odswieza_indeks(): void
    {
        // Indeks z wyłączonym odświeżaniem (jak vN+1 w trakcie migracji):
        // bez `_refresh` count widziałby 0.
        $this->client->indices()->putSettings(['index' => $this->index, 'body' => ['index' => ['refresh_interval' => '-1']]]);
        $body = [];
        foreach (range(1, 2000) as $id) {
            $body[] = ['index' => ['_index' => $this->index, '_id' => (string) $id]];
            $body[] = $this->product($id);
        }
        $this->client->bulk(['body' => $body]);

        self::assertStringContainsString('GATE=PASS', $this->gate()->getDisplay());
    }

    /** @param array<string, string> $options */
    private function gate(array $options = []): CommandTester
    {
        $tester = new CommandTester(new IndexGateCommand(new IndexGate($this->client, $this->fakeCatalog(fn () => $this->catalogCount))));
        $tester->execute(['--index' => $this->index] + $options);

        return $tester;
    }
}
