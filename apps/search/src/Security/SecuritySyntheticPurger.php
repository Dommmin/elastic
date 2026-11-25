<?php

namespace App\Security;

use App\Seller360\Complaints\ComplaintsSectionRefresher;
use Elastic\Elasticsearch\Client;

/**
 * Sprzątanie po `security:simulate` (ETAP 15). Kolejność:
 *  1. ZANIM cokolwiek zniknie: hasze IP syntetycznych recenzji i sprzedawcy z reklamacjami `SEC-*`
 *     (potem nie byłoby ich skąd wziąć);
 *  2. zdarzenia `labels.synthetic: true` z `events-security-*`;
 *  3. alerty `labels.synthetic: true` oraz dokumenty `review-ip-burst` na syntetycznych haszach — konektor Kibany
 *     zapisuje zawsze `synthetic: false`, więc rozpoznajemy go po encji;
 *  4. kliknięcia symulatora z `events-user-*` (sesje `secsim-*`; `events-user` nie ma pola `labels`);
 *  5. reklamacje `SEC-*` z `complaints` i `complaints-unmatched` (prefiks, `SIM-*` ETAPU 13 zostają);
 *  6. sekcja `complaints` seller-360 dotkniętych sprzedawców przeliczona istniejącym refresherem; sprzedawca
 *     bez żadnej reklamacji dostaje pustą sekcję (`zeroMissing`).
 * Wszędzie `conflicts=proceed`, `refresh` i `ignore_unavailable` (brak indeksu to 0, nie błąd).
 */
class SecuritySyntheticPurger
{
    public const TICKET_PREFIX = 'SEC-';

    public const SESSION_PREFIX = 'secsim-';

    private const PAGE = 1000;

    public function __construct(
        private readonly Client $client,
        private readonly ComplaintsSectionRefresher $refresher,
        private readonly string $eventsIndex = 'events-security-*',
        private readonly string $alertsIndex = 'alerts-security',
        private readonly string $userEventsIndex = 'events-user-*',
        private readonly string $complaintsIndex = 'complaints',
        private readonly string $unmatchedIndex = 'complaints-unmatched',
    ) {
    }

    /** @return array{events: int, alerts: int, clicks: int, complaints: int, sections: int} */
    public function purge(): array
    {
        $synthetic = ['term' => ['labels.synthetic' => true]];
        $hashes = $this->terms($this->eventsIndex, 'client.ip_hash', $synthetic);
        $sellers = array_map('intval', $this->terms($this->complaintsIndex, 'seller_id', ['prefix' => ['ticket_id' => self::TICKET_PREFIX]]));
        sort($sellers);

        $alertQuery = ['bool' => ['minimum_should_match' => 1, 'should' => [$synthetic]]];
        foreach (array_chunk($hashes, 10_000) as $chunk) {
            $alertQuery['bool']['should'][] = ['bool' => ['filter' => [
                ['term' => ['rule.id' => 'review-ip-burst']],
                ['terms' => ['entity.id' => $chunk]],
            ]]];
        }

        $result = [
            'events' => $this->delete($this->eventsIndex, $synthetic),
            'alerts' => $this->delete($this->alertsIndex, $alertQuery),
            'clicks' => $this->delete($this->userEventsIndex, ['prefix' => ['session.id' => self::SESSION_PREFIX]]),
            'complaints' => $this->delete($this->complaintsIndex.','.$this->unmatchedIndex, ['prefix' => ['ticket_id' => self::TICKET_PREFIX]]),
            'sections' => 0,
        ];
        if ($sellers !== []) {
            $result['sections'] = $this->refresher->refreshSellers($sellers, null, null, true);
        }

        return $result;
    }

    /**
     * Wszystkie wartości pola w dokumentach z zapytania (composite z `after_key`, bez sztywnego limitu).
     *
     * @param  array<string, mixed>  $query
     * @return list<string>
     */
    private function terms(string $index, string $field, array $query): array
    {
        $values = [];
        $after = null;
        do {
            $composite = ['size' => self::PAGE, 'sources' => [['v' => ['terms' => ['field' => $field]]]]];
            if ($after !== null) {
                $composite['after'] = $after;
            }
            $response = $this->client->search(['index' => $index, 'ignore_unavailable' => true, 'allow_no_indices' => true, 'body' => [
                'size' => 0,
                'query' => $query,
                'aggs' => ['values' => ['composite' => $composite]],
            ]])->asArray();

            $page = $response['aggregations']['values'] ?? ['buckets' => []];
            foreach ($page['buckets'] as $bucket) {
                $values[] = (string) $bucket['key']['v'];
            }
            $after = $page['after_key'] ?? null;
        } while ($after !== null && $page['buckets'] !== []);

        return $values;
    }

    /** @param array<string, mixed> $query */
    private function delete(string $index, array $query): int
    {
        $response = $this->client->deleteByQuery([
            'index' => $index,
            'ignore_unavailable' => true,
            'allow_no_indices' => true,
            'conflicts' => 'proceed',
            'refresh' => true,
            'body' => ['query' => $query],
        ])->asArray();

        return (int) ($response['deleted'] ?? 0);
    }
}
