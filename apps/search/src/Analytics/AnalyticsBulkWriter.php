<?php

namespace App\Analytics;

use Elastic\Elasticsearch\Client;

/**
 * Zapis zdarzeń analitycznych jednym `_bulk` z `create` (ETAP 12, spec sekcja 5).
 *
 * `create` + `_id` z mappera: powtórka z at-least-once dostaje 409, który liczymy
 * jako duplikat (sukces deduplikacji), nie błąd. Błąd całego żądania (sieć, 5xx)
 * leci wyjątkiem — Messenger ponowi całą paczkę, a duplikaty odsieje 409.
 *
 * `require_data_stream=true`: zapis przed `make analytics-setup` (brak szablonów)
 * nie zakłada po cichu zwykłego indeksu `events-*-default`, który potem zablokowałby
 * utworzenie data streamu o tej nazwie. ES odrzuca taką pozycję (4xx) → `rejected`
 * → wiadomość ląduje w transporcie `failed` i da się ją powtórzyć po setupie.
 */
class AnalyticsBulkWriter
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * @param  list<array{index: string, id: string, doc: array<string, mixed>}>  $items
     */
    public function write(array $items): BulkOutcome
    {
        if ($items === []) {
            return new BulkOutcome();
        }

        $body = [];
        foreach ($items as $item) {
            $body[] = ['create' => ['_index' => $item['index'], '_id' => $item['id']]];
            $body[] = $item['doc'];
        }

        $response = $this->client->bulk(['body' => $body, 'require_data_stream' => true])->asArray();

        $entries = $response['items'] ?? null;
        if (! \is_array($entries) || \count($entries) !== \count($items)) {
            throw new \UnexpectedValueException(\sprintf('_bulk: wysłano %d akcji, odpowiedź ma %s pozycji.', \count($items), \is_array($entries) ? \count($entries) : 'brak'));
        }

        $created = 0;
        $duplicates = 0;
        $rejected = [];
        $rejectedErrors = [];
        $retryable = [];
        // Odpowiedź zachowuje kolejność akcji — pozycja i-ta odpowiada i-temu dokumentowi.
        foreach (array_values($entries) as $i => $entry) {
            $result = $entry[array_key_first($entry)];
            $status = (int) ($result['status'] ?? 0);
            $ref = BulkOutcome::ref($items[$i]['index'], $items[$i]['id']);

            if ($status === 409) {
                ++$duplicates;
            } elseif ($status >= 200 && $status < 300) {
                ++$created;
            } elseif ($status === 429 || $status >= 500) {
                $retryable[] = $ref;
            } else {
                $rejected[] = $ref;
                $rejectedErrors[$ref] = self::describe($result['error'] ?? null, $status);
            }
        }

        return new BulkOutcome($created, $duplicates, $rejected, $retryable, $rejectedErrors);
    }

    /**
     * `type: reason` z błędu pozycji `_bulk` — trafia do komunikatu wiadomości w `failed`.
     */
    private static function describe(mixed $error, int $status): string
    {
        if (\is_string($error)) {
            return $error;
        }
        if (! \is_array($error)) {
            return "status {$status}";
        }
        $type = (string) ($error['type'] ?? "status {$status}");
        $reason = isset($error['reason']) ? (string) $error['reason'] : '';

        return $reason === '' ? $type : "{$type}: {$reason}";
    }
}
