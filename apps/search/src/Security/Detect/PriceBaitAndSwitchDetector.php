<?php

namespace App\Security\Detect;

use App\Security\Alert;
use App\Security\Detector;
use Elastic\Elasticsearch\Client;

/**
 * Reguła `price-bait-and-switch`: obniżka ceny oferty o ≥ 15%, potem ≥ 20 kliknięć tej oferty,
 * potem dwie podwyżki — wszystko w ciągu 48 h. To wzorzec „wabika”: sprzedawca ściąga ruch niską ceną
 * i podnosi ją, zanim kupujący się zorientują.
 *
 * Sekwencję wykrywa EQL (plik `.eql` obok reguł detekcji) na zdarzeniach z DWÓCH źródeł:
 * `events-security-*` (zmiany cen) i `events-user-*` (kliknięcia), połączonych po `offer.id`.
 * EQL nie umie liczyć, więc post-filtr w PHP (`accept`) sprawdza, że żadna zmiana nie jest skokiem
 * powyżej 30% (to łapie inna reguła) i że cena końcowa to ≥ 1,3 × cena sprzed obniżki.
 *
 * Zakres: alert należy do przebiegu, w którego `[from, to)` wypada OSTATNIA podwyżka sekwencji.
 * Sekwencja może zacząć się do 48 h wcześniej, więc zapytanie sięga o tyle wstecz (filtr czasu),
 * a odsiew po końcu robi PHP. Dzięki temu job z oknem 2 h nie gubi sekwencji rozpoczętych wczoraj,
 * a backfill 15 dni nie dubluje (klucz alertu: offer.id + czas obniżki).
 *
 * EQL nie ma paginacji, tylko `size`; przy nasyceniu (liczba sekwencji = size) rzucamy wyjątek zamiast
 * po cichu ucinać wynik.
 */
final class PriceBaitAndSwitchDetector implements Detector
{
    public const RULE_ID = 'price-bait-and-switch';

    private const PRICE_EVENT = 'offer.price_changed';
    private const CLICK_EVENT = 'user.clicked_result';
    private const MAX_STEP_PCT = 30;
    /** Cena końcowa ≥ 1,3 × cena sprzed obniżki; liczone na całkowitych groszach (×10), bez błędu zmiennoprzecinkowego. */
    private const MIN_RATIO_X10 = 13;
    private const LOOKBACK = '48 hours';
    private const SIZE = 2000;

    public function __construct(
        private readonly Client $client,
        private readonly string $eqlFile = '/infra/elasticsearch/detection/eql/price-bait-and-switch.eql',
        private readonly string $securityIndex = 'events-security-*',
        private readonly string $userIndex = 'events-user-*',
    ) {
    }

    public function id(): string
    {
        return self::RULE_ID;
    }

    /**
     * Post-filtr sekwencji: zdarzenia w kolejności czasu (obniżka, kliknięcia, podwyżki); kliknięcia pomijamy.
     *
     * @param  list<array<string, mixed>>  $sequenceEvents `_source` zdarzeń sekwencji
     */
    public static function accept(array $sequenceEvents): bool
    {
        $changes = self::priceChanges($sequenceEvents);
        if (\count($changes) < 2) {
            return false;
        }

        foreach ($changes as $change) {
            $pct = $change['offer']['change_pct'] ?? null;
            if (! is_numeric($pct) || abs((float) $pct) > self::MAX_STEP_PCT) {
                return false;
            }
        }

        $before = (int) ($changes[0]['offer']['old_price_cents'] ?? 0);
        $final = (int) ($changes[array_key_last($changes)]['offer']['new_price_cents'] ?? 0);

        return $before > 0 && $final * 10 >= $before * self::MIN_RATIO_X10;
    }

    public function detect(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $utc = new \DateTimeZone('UTC');
        $from = $from->setTimezone($utc);
        $to = $to->setTimezone($utc);
        $format = 'Y-m-d\TH:i:s.v\Z';

        $response = $this->client->eql()->search([
            'index' => $this->securityIndex.','.$this->userIndex,
            'body' => [
                'query' => $this->query(),
                'timestamp_field' => '@timestamp',
                'event_category_field' => 'event.type',
                'size' => self::SIZE,
                'filter' => ['range' => ['@timestamp' => [
                    'gte' => $from->modify('-'.self::LOOKBACK)->format($format),
                    'lt' => $to->format($format),
                ]]],
            ],
        ])->asArray();

        $sequences = $response['hits']['sequences'] ?? [];
        if (\count($sequences) >= self::SIZE) {
            throw new \RuntimeException(\sprintf('EQL %s zwrócił %d sekwencji (limit size): zawęź zakres, inaczej część zostałaby pominięta.', self::RULE_ID, \count($sequences)));
        }

        $alerts = [];
        foreach ($sequences as $sequence) {
            $events = array_map(static fn (array $hit): array => $hit['_source'], $sequence['events'] ?? []);
            if (! self::accept($events)) {
                continue;
            }
            $changes = self::priceChanges($events);
            $first = $changes[0];
            $last = $changes[array_key_last($changes)];
            $start = new \DateTimeImmutable($first['@timestamp']);
            $end = new \DateTimeImmutable($last['@timestamp']);
            if ($end < $from || $end >= $to) {
                continue;
            }

            $before = (int) $first['offer']['old_price_cents'];
            $final = (int) $last['offer']['new_price_cents'];
            $clicks = \count(array_filter($events, static fn (array $e): bool => ($e['event']['type'] ?? null) === self::CLICK_EVENT));
            $alerts[] = new Alert(
                ruleId: self::RULE_ID,
                ruleKind: 'eql',
                severity: 'high',
                entityType: 'offer',
                entityId: (string) $sequence['join_keys'][0],
                windowStart: $start,
                windowEnd: $end,
                message: \sprintf('Oferta: obniżka %d → %d gr, %d kliknięć, podwyżka do %d gr (%.2f× ceny sprzed obniżki) w %d h', $before, $first['offer']['new_price_cents'], $clicks, $final, $final / $before, max(1, (int) round(($end->getTimestamp() - $start->getTimestamp()) / 3600))),
                evidence: [
                    'price_before_cents' => $before,
                    'price_dropped_cents' => (int) $first['offer']['new_price_cents'],
                    'price_final_cents' => $final,
                    'ratio' => round($final / $before, 4),
                    'clicks' => $clicks,
                    'price_changes' => \count($changes),
                ],
                synthetic: $this->isSynthetic($events),
            );
        }

        return $alerts;
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return list<array<string, mixed>>
     */
    private static function priceChanges(array $events): array
    {
        return array_values(array_filter($events, static fn (array $e): bool => ($e['event']['type'] ?? null) === self::PRICE_EVENT));
    }

    /** Syntetyczny, gdy którekolwiek zdarzenie sekwencji ma `labels.synthetic` (kliknięcia nie mają tego pola = false). */
    private function isSynthetic(array $events): bool
    {
        foreach ($events as $event) {
            if (($event['labels']['synthetic'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    private function query(): string
    {
        $eql = trim((string) file_get_contents($this->eqlFile));
        if (! str_starts_with($eql, 'sequence by offer.id')) {
            throw new \RuntimeException("Plik {$this->eqlFile} nie zawiera zapytania EQL zaczynającego się od `sequence by offer.id`.");
        }

        return $eql;
    }
}
