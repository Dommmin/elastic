<?php

namespace App\Security\Simulation;

/**
 * Źródło popularności produktów dla `security:simulate` (ETAP 15): próg p95 reguły `price-hug-threshold`
 * i produkty po obu stronach progu. Interfejs, żeby test podstawił fałszywkę bez Elasticsearcha.
 */
interface PopularitySource
{
    /** `params.p95` z dokumentu `security-rules/price-hug-threshold`; null, gdy reguły nie załadowano. */
    public function p95(): ?float;

    /**
     * @return list<int> id produktów z `popularity >= $min`, rosnąco po id
     */
    public function productsAtLeast(float $min, int $limit): array;

    /**
     * @return list<int> id produktów z `popularity < $max`, rosnąco po id
     */
    public function productsBelow(float $max, int $limit): array;
}
