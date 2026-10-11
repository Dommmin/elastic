<?php

namespace App\Service;

/**
 * Wynik GET /api/internal/products/{id}/projection: stan produktu (albo
 * jego brak) RAZEM z chwilą odczytu z zegara Postgresa catalogu.
 *
 * `projectedAtUs` jest wersją dokumentu w ES — także przy braku produktu,
 * żeby spóźnione usunięcie nie skasowało nowszego dokumentu
 * (docs/superpowers/specs/2026-10-11-panel-edycji-design.md, część 1).
 */
final readonly class ProductProjection
{
    /**
     * @param  array<string, mixed>|null  $data  null = produkt nie istnieje (404)
     */
    public function __construct(
        public ?array $data,
        public int $projectedAtUs,
    ) {
    }

    /** @phpstan-assert-if-true !null $this->data */
    public function exists(): bool
    {
        return $this->data !== null;
    }
}
