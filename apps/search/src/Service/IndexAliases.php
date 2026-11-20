<?php

namespace App\Service;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;

/**
 * Dwa aliasy produktów (ETAP 10): `products-search` to ODCZYT (catalog),
 * `products-write` to ZAPIS (konsument i reproject, z `is_write_index`).
 * Rozdzielenie pozwala w trakcie reindeksu pisać już do nowego indeksu,
 * a czytać jeszcze z kompletnego starego (docs/superpowers/specs/
 * 2026-11-19-etap-10-operacje-na-indeksach-design.md, 5.1).
 *
 * Nazwy aliasów są parametrami tylko po to, by testy integracyjne działały
 * na jednorazowych aliasach, nie na żywych.
 */
final class IndexAliases
{
    public const READ = 'products-search';
    public const WRITE = 'products-write';

    public function __construct(
        private readonly Client $client,
        public readonly string $read = self::READ,
        public readonly string $write = self::WRITE,
    ) {
    }

    /** Indeks, na który wskazuje alias odczytu (null — alias nie istnieje). */
    public function readTarget(): ?string
    {
        return $this->target($this->read);
    }

    /** Indeks zapisu: jedyny albo ten z `is_write_index: true` (null — brak aliasu). */
    public function writeTarget(): ?string
    {
        return $this->target($this->write);
    }

    /**
     * Atomowo: remove `from` + add `to` w JEDNYM `_aliases` — nie ma chwili,
     * w której alias nie wskazuje na nic. Alias zapisu dostaje `is_write_index`.
     */
    public function swap(string $alias, string $from, string $to): void
    {
        $this->client->indices()->updateAliases(['body' => ['actions' => [
            ['remove' => ['index' => $from, 'alias' => $alias]],
            ['add' => $this->addAction($alias, $to)],
        ]]]);
    }

    /** Dopina alias do indeksu (nic nie zdejmuje). */
    public function attach(string $alias, string $index): void
    {
        $this->client->indices()->updateAliases(['body' => ['actions' => [
            ['add' => $this->addAction($alias, $index)],
        ]]]);
    }

    /** `products-v7` → 7. */
    public static function versionOf(string $index): int
    {
        if (preg_match('/-v(\d+)$/', $index, $m) !== 1) {
            throw new \InvalidArgumentException("Nazwa indeksu bez przyrostka -vN: {$index}");
        }

        return (int) $m[1];
    }

    /** @return array<string, mixed> */
    private function addAction(string $alias, string $index): array
    {
        $action = ['index' => $index, 'alias' => $alias];
        if ($alias === $this->write) {
            $action['is_write_index'] = true;
        }

        return $action;
    }

    private function target(string $alias): ?string
    {
        try {
            $indices = $this->client->indices()->getAlias(['name' => $alias])->asArray();
        } catch (ClientResponseException $e) {
            if ($e->getCode() === 404) {
                return null;
            }

            throw $e;
        }

        if (count($indices) === 1) {
            return (string) array_key_first($indices);
        }

        foreach ($indices as $index => $data) {
            if (($data['aliases'][$alias]['is_write_index'] ?? false) === true) {
                return (string) $index;
            }
        }

        return null;
    }
}
