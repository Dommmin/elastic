<?php

namespace App\Services\Search\Lab;

use App\Console\Commands\LabExportCommand;
use App\Services\Search\RelevanceJudgments;
use InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;

/**
 * Oceny dla laboratorium semantycznego (ETAP 14): reguły jak w
 * `tests/relevance/queries.yaml` (rozwija je `RelevanceJudgments` z bazy),
 * przecięte z próbką `id % LabExportCommand::MODULO = 0` — tylko te produkty
 * są w `products-lab-v1`, więc ocena produktu spoza próbki zaniżyłaby ideał.
 */
class LabJudgments
{
    public const GROUPS = ['lexical', 'descriptive', 'exact'];

    public function __construct(private readonly RelevanceJudgments $judgments = new RelevanceJudgments) {}

    /**
     * @param  array<int, array<string, mixed>>  $rules
     * @return array<string, int> id => ocena, tylko produkty z próbki
     */
    public function forQuery(array $rules, string $query = ''): array
    {
        $ratings = [];
        foreach ($this->judgments->resolve($rules) as $id => $grade) {
            if ((int) $id % LabExportCommand::MODULO === 0) {
                $ratings[(string) $id] = $grade;
            }
        }

        if ($ratings === []) {
            throw new InvalidArgumentException(
                "Zapytanie „{$query}” nie ma żadnych ocen w próbce (id % ".LabExportCommand::MODULO.' = 0): '
                .json_encode($rules, JSON_UNESCAPED_UNICODE)
            );
        }

        return $ratings;
    }

    /**
     * @return array<int, array{query: string, group: string, ratings: array<string, int>}>
     */
    public function load(string $path): array
    {
        $data = Yaml::parseFile($path);
        $out = [];

        foreach ($data['queries'] ?? [] as $entry) {
            $query = (string) ($entry['query'] ?? throw new InvalidArgumentException('Pozycja bez `query`.'));
            $group = (string) ($entry['group'] ?? '');

            if (! in_array($group, self::GROUPS, true)) {
                throw new InvalidArgumentException("Zapytanie „{$query}”: nieznana grupa „{$group}” (dozwolone: ".implode(', ', self::GROUPS).').');
            }

            $out[] = [
                'query' => $query,
                'group' => $group,
                'ratings' => $this->forQuery($entry['relevant'] ?? [], $query),
            ];
        }

        return $out;
    }
}
