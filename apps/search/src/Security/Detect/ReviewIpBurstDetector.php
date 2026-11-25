<?php

namespace App\Security\Detect;

use App\Security\Alert;
use App\Security\Detector;
use Elastic\Elasticsearch\Client;

/**
 * Backfill reguły `review-ip-burst`: ≥ 5 różnych `user.id` z jednym `client.ip_hash` w kubełku 1 h.
 *
 * Tekst ES|QL czytamy z pliku reguły Kibany (`params.esqlQuery.esql`), więc live (Kibana Alerting)
 * i historia liczą DOKŁADNIE to samo. Doklejamy tylko filtr czasu zaraz po `FROM`. Początek zakresu
 * zaokrąglamy w dół do pełnej godziny, żeby kubełek na brzegu nie był policzony od połowy
 * (niepełny kubełek dałby inną liczbę użytkowników niż live, a alert ma to samo id).
 */
final class ReviewIpBurstDetector implements Detector
{
    public const RULE_ID = 'review-ip-burst';

    public function __construct(
        private readonly Client $client,
        private readonly string $ruleFile = '/infra/kibana/rules/security-review-ip-burst.json',
        private readonly string $eventsIndex = 'events-security-*',
    ) {
    }

    public function id(): string
    {
        return self::RULE_ID;
    }

    public function detect(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $utc = new \DateTimeZone('UTC');
        $from = $from->setTimezone($utc);
        $from = $from->setTime((int) $from->format('G'), 0);
        $format = 'Y-m-d\TH:i:s.v\Z';

        $response = $this->client->esql()->query(['body' => ['query' => $this->query(
            $from->format($format),
            $to->setTimezone($utc)->format($format),
        )]])->asArray();

        $columns = array_column($response['columns'] ?? [], 'name');
        $alerts = [];
        foreach ($response['values'] ?? [] as $values) {
            $row = array_combine($columns, $values);
            $hash = (string) $row['client.ip_hash'];
            $start = new \DateTimeImmutable((string) $row['hour']);
            $end = $start->modify('+1 hour');

            $alerts[] = new Alert(
                ruleId: self::RULE_ID,
                ruleKind: 'esql',
                severity: 'medium',
                entityType: 'ip_hash',
                entityId: $hash,
                windowStart: $start,
                windowEnd: $end,
                message: \sprintf('%d różnych użytkowników wystawiło %d recenzji z jednego odcisku IP w ciągu godziny', $row['users'], $row['reviews']),
                evidence: ['users' => (int) $row['users'], 'reviews' => (int) $row['reviews']],
                synthetic: $this->isSynthetic($hash, $start, $end),
            );
        }

        return $alerts;
    }

    private function query(string $from, string $to): string
    {
        $rule = json_decode((string) file_get_contents($this->ruleFile), true, flags: \JSON_THROW_ON_ERROR);
        $esql = $rule['params']['esqlQuery']['esql'] ?? null;
        if (! \is_string($esql) || ! str_starts_with($esql, 'FROM events-security-*')) {
            throw new \RuntimeException("Plik {$this->ruleFile} nie ma ES|QL zaczynającego się od FROM events-security-*.");
        }

        return \sprintf(
            "FROM %s\n| WHERE @timestamp >= \"%s\" AND @timestamp < \"%s\"%s",
            $this->eventsIndex,
            $from,
            $to,
            substr($esql, \strlen('FROM events-security-*')),
        );
    }

    /** Alert jest syntetyczny, gdy WSZYSTKIE recenzje kubełka niosą `labels.synthetic` (symulator). */
    private function isSynthetic(string $hash, \DateTimeImmutable $start, \DateTimeImmutable $end): bool
    {
        $bucket = [
            ['term' => ['event.type' => 'review.created']],
            ['term' => ['client.ip_hash' => $hash]],
            ['range' => ['@timestamp' => ['gte' => $start->format(\DATE_ATOM), 'lt' => $end->format(\DATE_ATOM)]]],
        ];
        $count = fn (array $filter): int => (int) $this->client->count([
            'index' => $this->eventsIndex,
            'body' => ['query' => ['bool' => ['filter' => $filter]]],
        ])->asArray()['count'];

        $total = $count($bucket);

        return $total > 0 && $total === $count([...$bucket, ['term' => ['labels.synthetic' => true]]]);
    }
}
