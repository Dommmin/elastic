<?php

namespace App\Security;

/**
 * Skuteczność reguł detekcji wobec prawdy podstawowej z `security:simulate` (ETAP 15, `security:report`).
 *
 * Dopasowanie: alert i przypadek mają tę samą regułę, typ i id encji, a okna się przecinają. Okna detektorów
 * są półotwarte `[start, end)` (kubełek godziny, doba), więc samo zetknięcie brzegami to nie przecięcie;
 * okno punktowe (percolator: `start = end`) pasuje, gdy leży w oknie drugiej strony (brzegi włącznie).
 *
 * Liczenie per reguła: zasadzony z ≥ 1 alertem = TP (kilka alertów jednego przypadku to nadal jedno TP),
 * zasadzony bez alertu = FN, bliskie pudło z alertem = FP i `fp_near_miss`, alert bez żadnego przypadku = FP.
 * Precyzja i czułość bez mianownika to `null` (nie dzielimy przez zero).
 */
final class DetectionScore
{
    /** Kolejność reguł w raporcie; reguły spoza listy trafiają na koniec. */
    public const RULES = ['price-hug-threshold', 'fresh-account-5star', 'review-ip-burst', 'price-bait-and-switch', 'complaints-spike'];

    /**
     * @param  list<array<string, string>>  $cases   przypadki z `planted-security.json` (`rule`, `type`, `entity_type`, `entity_id`, `window_start`, `window_end`)
     * @param  list<array<string, mixed>>   $alerts  `_source` dokumentów `alerts-security`
     * @return array<string, array{tp: int, fp: int, fp_near_miss: int, fn: int, precision: ?float, recall: ?float}>
     */
    public static function score(array $cases, array $alerts): array
    {
        $counts = [];
        $touch = static function (string $rule) use (&$counts): void {
            $counts[$rule] ??= ['tp' => 0, 'fp' => 0, 'fp_near_miss' => 0, 'fn' => 0];
        };

        $matchedAlerts = [];
        foreach ($cases as $case) {
            $rule = (string) $case['rule'];
            $touch($rule);
            $hit = false;
            foreach ($alerts as $i => $alert) {
                if (self::matches($case, $alert)) {
                    $hit = true;
                    $matchedAlerts[$i] = true;
                }
            }
            if ($case['type'] === 'planted') {
                $counts[$rule][$hit ? 'tp' : 'fn']++;
            } elseif ($hit) {
                $counts[$rule]['fp']++;
                $counts[$rule]['fp_near_miss']++;
            }
        }
        foreach ($alerts as $i => $alert) {
            $rule = (string) ($alert['rule']['id'] ?? 'unknown');
            $touch($rule);
            if (! isset($matchedAlerts[$i])) {
                $counts[$rule]['fp']++;
            }
        }

        $order = array_flip(self::RULES);
        uksort($counts, static fn (string $a, string $b): int => [$order[$a] ?? \PHP_INT_MAX, $a] <=> [$order[$b] ?? \PHP_INT_MAX, $b]);

        $result = [];
        foreach ($counts as $rule => $c) {
            $result[$rule] = $c + [
                'precision' => $c['tp'] + $c['fp'] > 0 ? round($c['tp'] / ($c['tp'] + $c['fp']), 4) : null,
                'recall' => $c['tp'] + $c['fn'] > 0 ? round($c['tp'] / ($c['tp'] + $c['fn']), 4) : null,
            ];
        }

        return $result;
    }

    /**
     * R7: oceniamy tylko alerty z detektorów PHP — `_id` równy sha1 klucza alertu z jego własnych pól. Dokumenty
     * konektora Kibany (losowe `_id`) liczymy osobno (`kibana`). Z ocenianych bierzemy syntetyczne i te na encji
     * z prawdy (reguła + encja); pozostałe niesyntetyczne to prawdziwe dane spoza symulacji (`unrelated`).
     *
     * @param  list<array<string, string>>  $cases
     * @param  list<array{_id: string, _source: array<string, mixed>}>  $hits
     * @return array{scored: list<array<string, mixed>>, kibana: int, unrelated: int}
     */
    public static function partition(array $cases, array $hits): array
    {
        $entities = [];
        foreach ($cases as $case) {
            $entities[self::entityKey((string) $case['rule'], (string) $case['entity_type'], (string) $case['entity_id'])] = true;
        }

        $scored = [];
        $kibana = 0;
        $unrelated = 0;
        foreach ($hits as $hit) {
            $source = $hit['_source'];
            if (self::alertId($source) !== $hit['_id']) {
                $kibana++;
                continue;
            }
            $key = self::entityKey((string) $source['rule']['id'], (string) $source['entity']['type'], (string) $source['entity']['id']);
            if (($source['labels']['synthetic'] ?? false) === true || isset($entities[$key])) {
                $scored[] = $source;
            } else {
                $unrelated++;
            }
        }

        return ['scored' => $scored, 'kibana' => $kibana, 'unrelated' => $unrelated];
    }

    /** sha1 klucza alertu (jak {@see Alert::id()}) z pól dokumentu; null, gdy pól brak lub okno nie jest datą. */
    public static function alertId(array $source): ?string
    {
        $rule = $source['rule']['id'] ?? null;
        $type = $source['entity']['type'] ?? null;
        $id = $source['entity']['id'] ?? null;
        $start = self::time($source['window']['start'] ?? null);
        if (! \is_string($rule) || ! \is_string($type) || ! \is_scalar($id) || $start === null) {
            return null;
        }

        return sha1(\sprintf('%s|%s|%s|%s', $rule, $type, $id, $start->format(\DATE_ATOM)));
    }

    /**
     * @param  array<string, string>  $case
     * @param  array<string, mixed>   $alert
     */
    private static function matches(array $case, array $alert): bool
    {
        if (($alert['rule']['id'] ?? null) !== $case['rule']
            || ($alert['entity']['type'] ?? null) !== $case['entity_type']
            || (string) ($alert['entity']['id'] ?? '') !== (string) $case['entity_id']) {
            return false;
        }
        $aStart = self::time($alert['window']['start'] ?? null);
        $aEnd = self::time($alert['window']['end'] ?? null) ?? $aStart;
        $cStart = self::time($case['window_start']);
        $cEnd = self::time($case['window_end']) ?? $cStart;
        if ($aStart === null || $cStart === null) {
            return false;
        }

        $from = max($aStart, $cStart);
        $to = min($aEnd, $cEnd);
        $point = $aStart == $aEnd || $cStart == $cEnd;

        return $point ? $from <= $to : $from < $to;
    }

    private static function time(mixed $value): ?\DateTimeImmutable
    {
        if (! \is_string($value) || $value === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    private static function entityKey(string $rule, string $type, string $id): string
    {
        return "{$rule}|{$type}|{$id}";
    }
}
