<?php

namespace App\Security;

use Elastic\Elasticsearch\Client;

/**
 * Zapis alertów do `alerts-security` jednym `_bulk` z `index` i `_id` = {@see Alert::id()}:
 * powtórka nadpisuje ten sam dokument, więc zapis jest idempotentny. Błąd pojedynczej pozycji
 * rzuca wyjątek (alert nie może zginąć po cichu), a całego żądania leci z klienta.
 */
class AlertWriter
{
    public const INDEX = 'alerts-security';

    public function __construct(
        private readonly Client $client,
        private readonly string $index = self::INDEX,
    ) {
    }

    /**
     * @param  list<Alert>  $alerts
     * @return int liczba zapisanych (utworzonych albo nadpisanych) alertów
     */
    public function write(array $alerts): int
    {
        if ($alerts === []) {
            return 0;
        }

        $body = [];
        foreach ($alerts as $alert) {
            $body[] = ['index' => ['_index' => $this->index, '_id' => $alert->id()]];
            $body[] = $alert->toDocument();
        }

        $response = $this->client->bulk(['body' => $body])->asArray();

        $written = 0;
        $errors = [];
        foreach ($response['items'] ?? [] as $entry) {
            $result = $entry[array_key_first($entry)];
            if (($result['status'] ?? 500) < 300) {
                ++$written;
            } else {
                $errors[] = ($result['error']['type'] ?? 'status '.($result['status'] ?? '?')).': '.($result['error']['reason'] ?? '');
            }
        }
        if ($errors !== []) {
            throw new \RuntimeException(\sprintf('Zapis alertów: %d z %d pozycji odrzucone (%s).', \count($errors), \count($alerts), implode('; ', \array_slice($errors, 0, 3))));
        }

        return $written;
    }
}
