<?php

namespace App\Service;

use Elastic\Elasticsearch\Client;

/**
 * Zmiana nazwy sprzedawcy w dokumentach ES przez `_update_by_query` (ETAP 10, spec 5.7).
 *
 * Zapytanie dopasowuje TYLKO oferty tego sprzedawcy, które mają jeszcze inną nazwę
 * — oba warunki w jednej klauzuli `nested`, czyli dla tej samej oferty. Dzięki temu
 * komenda jest idempotentna: powtórka po przerwaniu (Ctrl-C, `_cancel`) pomija
 * dokumenty już poprawione i robi tylko resztę.
 *
 * WERSJE: `_update_by_query` nie zna wersji zewnętrznej — zapisuje dokument
 * z wersją wewnętrzną V+1. Wersja zewnętrzna to `projected_at_us` (µs od epoki,
 * rzędu 1,7e15), więc +1 nie zmienia kolejności: późniejsze zdarzenie strumienia
 * (V' > V+1) nadal wygrywa, a duplikat sprzed zmiany nazwy nadal dostaje 409.
 * Dowód: SellerRenameIndexCommandTest.
 */
final class SellerRenamer
{
    private const SCRIPT = <<<'PAINLESS'
        for (def offer : ctx._source.offers) {
            if (String.valueOf(offer.seller_id) == params.seller_id && offer.seller != params.name) {
                offer.seller = params.name;
            }
        }
        PAINLESS;

    public function __construct(private readonly Client $client)
    {
    }

    /**
     * Startuje zadanie w tle i zwraca jego id (`node:id`).
     *
     * @param float $rps `requests_per_second` (-1 = bez limitu); da się zmienić w locie `_rethrottle`
     */
    public function start(string $index, string $sellerId, string $name, float $rps): string
    {
        // _update_by_query czyta przez search: dokumenty zapisane przed chwilą,
        // a jeszcze nieodświeżone, inaczej zostałyby pominięte.
        $this->client->indices()->refresh(['index' => $index]);

        $response = $this->client->updateByQuery([
            'index' => $index,
            'wait_for_completion' => false,
            'requests_per_second' => $rps,
            'conflicts' => 'proceed',
            'body' => [
                'query' => ['nested' => [
                    'path' => 'offers',
                    'query' => ['bool' => ['filter' => [
                        ['term' => ['offers.seller_id' => $sellerId]],
                        ['bool' => ['must_not' => [['term' => ['offers.seller' => $name]]]]],
                    ]]],
                ]],
                'script' => [
                    'lang' => 'painless',
                    'source' => self::SCRIPT,
                    'params' => ['seller_id' => $sellerId, 'name' => $name],
                ],
            ],
        ])->asArray();

        return (string) $response['task'];
    }
}
