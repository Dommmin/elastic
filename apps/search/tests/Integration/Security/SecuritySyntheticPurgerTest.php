<?php

namespace App\Tests\Integration\Security;

use App\Security\SecuritySyntheticPurger;
use App\Seller360\Complaints\ComplaintsSectionRefresher;
use App\Tests\Support\RealElasticsearch;
use Elastic\Elasticsearch\Client;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * `security:purge-synthetic` na PRAWDZIWYM Elasticsearchu, na izolowanych indeksach testowych (mapowania z repo).
 * Odświeżenie sekcji seller-360 sprawdzamy na zaślepce refreshera: liczy się, dla kogo i z `zeroMissing`.
 */
#[Group('integration')]
final class SecuritySyntheticPurgerTest extends TestCase
{
    use RealElasticsearch;

    private const DETECTION_DIR = '/infra/elasticsearch/detection';

    private Client $client;
    /** @var array<string, string> */
    private array $indices;
    private int $seq = 0;

    protected function setUp(): void
    {
        $this->client = $this->elasticsearchOrSkip();
        if (! is_file(self::DETECTION_DIR.'/index-alerts-security.json')) {
            self::markTestSkipped('Brak plików detekcji (mount search-consumer).');
        }
        $suffix = uniqid();
        $this->indices = [
            'events' => 'events-test-security-'.$suffix,
            'user' => 'events-test-user-'.$suffix,
            'alerts' => 'alerts-security-test-'.$suffix,
            'complaints' => 'complaints-test-'.$suffix,
            'unmatched' => 'complaints-test-unmatched-'.$suffix,
        ];
        $template = static fn (string $file): array => ['settings' => ['index.number_of_replicas' => 0], 'mappings' => json_decode(file_get_contents($file), true, flags: \JSON_THROW_ON_ERROR)['template']['mappings']];
        $alerts = json_decode(file_get_contents(self::DETECTION_DIR.'/index-alerts-security.json'), true, flags: \JSON_THROW_ON_ERROR);
        $alerts['settings']['index.number_of_replicas'] = 0;
        $complaints = ['settings' => ['index.number_of_replicas' => 0], 'mappings' => ['properties' => [
            'ticket_id' => ['type' => 'keyword'], 'seller_id' => ['type' => 'long'], 'created_at' => ['type' => 'date'],
        ]]];

        $this->client->indices()->create(['index' => $this->indices['events'], 'body' => $template(self::DETECTION_DIR.'/template-events-security.json')]);
        $this->client->indices()->create(['index' => $this->indices['user'], 'body' => $template('/infra/elasticsearch/analytics/template-events-user.json')]);
        $this->client->indices()->create(['index' => $this->indices['alerts'], 'body' => $alerts]);
        $this->client->indices()->create(['index' => $this->indices['complaints'], 'body' => $complaints]);
        $this->client->indices()->create(['index' => $this->indices['unmatched'], 'body' => $complaints]);
    }

    protected function tearDown(): void
    {
        foreach ($this->indices ?? [] as $index) {
            $this->client->indices()->delete(['index' => $index, 'ignore_unavailable' => true]);
        }
    }

    public function test_usuwa_tylko_dane_symulatora_i_odswieza_sekcje_dotknietych_sprzedawcow(): void
    {
        $this->review('hash-sym', synthetic: true);
        $this->review('hash-real', synthetic: false);
        $this->doc('user', ['@timestamp' => '2026-11-20T10:00:00Z', 'event' => ['id' => 'c1', 'type' => 'user.clicked_result'], 'session' => ['id' => 'secsim-7-0'], 'offer' => ['id' => '7']]);
        $this->doc('user', ['@timestamp' => '2026-11-20T10:00:00Z', 'event' => ['id' => 'c2', 'type' => 'user.clicked_result'], 'session' => ['id' => 'prawdziwa-sesja'], 'offer' => ['id' => '7']]);
        $this->alert('fresh-account-5star', 'user', '920000001', synthetic: true);
        $this->alert('fresh-account-5star', 'user', '42', synthetic: false);
        // Dokument konektora Kibany zawsze ma `synthetic: false` — rozpoznajemy go po syntetycznym haszu IP.
        $this->alert('review-ip-burst', 'ip_hash', 'hash-sym', synthetic: false);
        $this->alert('review-ip-burst', 'ip_hash', 'hash-real', synthetic: false);
        foreach ([['SEC-1-0000001', 5001], ['SEC-1-0000002', 5002], ['SIM-1-0000001', 5001], ['T-1', 5003]] as [$ticket, $seller]) {
            $this->doc('complaints', ['ticket_id' => $ticket, 'seller_id' => $seller, 'created_at' => '2026-11-20T10:00:00Z']);
        }
        $this->doc('unmatched', ['ticket_id' => 'SEC-1-0000003', 'created_at' => '2026-11-20T10:00:00Z']);
        $this->doc('unmatched', ['ticket_id' => 'SIM-1-0000009', 'created_at' => '2026-11-20T10:00:00Z']);
        $this->client->indices()->refresh(['index' => implode(',', $this->indices)]);

        $refresher = $this->createMock(ComplaintsSectionRefresher::class);
        $refresher->expects(self::once())->method('refreshSellers')
            ->with([5001, 5002], null, null, true)
            ->willReturn(2);

        $result = (new SecuritySyntheticPurger($this->client, $refresher, $this->indices['events'], $this->indices['alerts'], $this->indices['user'], $this->indices['complaints'], $this->indices['unmatched']))->purge();

        self::assertSame(1, $result['events']);
        self::assertSame(2, $result['alerts']);
        self::assertSame(1, $result['clicks']);
        self::assertSame(3, $result['complaints']);
        self::assertSame(2, $result['sections']);

        self::assertSame(['hash-real'], $this->values('events', 'client.ip_hash'));
        self::assertSame(['prawdziwa-sesja'], $this->values('user', 'session.id'));
        self::assertEqualsCanonicalizing(['42', 'hash-real'], $this->values('alerts', 'entity.id'));
        self::assertEqualsCanonicalizing(['SIM-1-0000001', 'T-1'], $this->values('complaints', 'ticket_id'));
        self::assertSame(['SIM-1-0000009'], $this->values('unmatched', 'ticket_id'));
    }

    public function test_bez_danych_symulatora_nic_nie_usuwa_i_nie_odswieza(): void
    {
        $this->review('hash-real', synthetic: false);
        $this->client->indices()->refresh(['index' => implode(',', $this->indices)]);
        $refresher = $this->createMock(ComplaintsSectionRefresher::class);
        $refresher->expects(self::never())->method('refreshSellers');

        $result = (new SecuritySyntheticPurger($this->client, $refresher, $this->indices['events'], $this->indices['alerts'], $this->indices['user'], $this->indices['complaints'], $this->indices['unmatched']))->purge();

        self::assertSame(['events' => 0, 'alerts' => 0, 'clicks' => 0, 'complaints' => 0, 'sections' => 0], $result);
    }

    private function review(string $hash, bool $synthetic): void
    {
        $doc = [
            '@timestamp' => '2026-11-20T10:00:00Z',
            'event' => ['id' => 'r'.++$this->seq, 'type' => 'review.created'],
            'review' => ['id' => (string) $this->seq, 'rating' => 5],
            'user' => ['id' => (string) (920000000 + $this->seq)],
            'client' => ['ip_hash' => $hash],
        ];
        if ($synthetic) {
            $doc['labels'] = ['synthetic' => true];
        }
        $this->doc('events', $doc);
    }

    private function alert(string $rule, string $entityType, string $entityId, bool $synthetic): void
    {
        $this->doc('alerts', [
            '@timestamp' => '2026-11-20T10:00:00+00:00',
            'rule' => ['id' => $rule, 'kind' => 'esql'],
            'severity' => 'medium',
            'entity' => ['type' => $entityType, 'id' => $entityId],
            'window' => ['start' => '2026-11-20T10:00:00+00:00', 'end' => '2026-11-20T11:00:00+00:00'],
            'message' => 'test',
            'labels' => ['synthetic' => $synthetic],
        ]);
    }

    /** @param array<string, mixed> $doc */
    private function doc(string $index, array $doc): void
    {
        $this->client->index(['index' => $this->indices[$index], 'body' => $doc]);
    }

    /** @return list<string> */
    private function values(string $index, string $field): array
    {
        $hits = $this->client->search(['index' => $this->indices[$index], 'body' => ['size' => 100, 'query' => ['match_all' => (object) []]]])->asArray()['hits']['hits'];
        $values = [];
        foreach ($hits as $hit) {
            $value = $hit['_source'];
            foreach (explode('.', $field) as $key) {
                $value = $value[$key] ?? null;
            }
            $values[] = (string) $value;
        }
        sort($values);

        return $values;
    }
}
