<?php

namespace App\Security;

use Elastic\Elasticsearch\Client;

/**
 * Czy detekcja ETAPU 15 jest skonfigurowana: indeks `alerts-security` istnieje (tworzy go
 * `make detection-setup`), a pliki reguł (ES|QL z Kibany, EQL) są zamontowane w kontenerze.
 *
 * Job `security-detect` biegnie w `seller360-scheduler` co 300 s także na stosie, na którym nikt nie
 * uruchomił ETAPU 15. Bez tej kontroli każdy przebieg kończyłby się wyjątkiem i logiem błędu.
 */
class DetectionReadiness
{
    /** @param list<string> $files */
    public function __construct(
        private readonly Client $client,
        private readonly string $alertsIndex = AlertWriter::INDEX,
        private readonly array $files = [
            '/infra/kibana/rules/security-review-ip-burst.json',
            '/infra/elasticsearch/detection/eql/price-bait-and-switch.eql',
        ],
    ) {
    }

    /** Powód braku konfiguracji albo null, gdy wszystko jest na miejscu. */
    public function missing(): ?string
    {
        foreach ($this->files as $file) {
            if (! is_file($file)) {
                return "brak pliku {$file} (montaż infra w kontenerze)";
            }
        }

        if (! $this->client->indices()->exists(['index' => $this->alertsIndex])->asBool()) {
            return "brak indeksu {$this->alertsIndex} (make detection-setup)";
        }

        return null;
    }
}
