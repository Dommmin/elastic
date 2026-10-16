<?php

namespace Tests\Unit\Ordering\InMemory;

use Ordering\Application\Port\OfferSnapshot;
use Ordering\Application\Port\OfferSnapshots;

final class FakeOfferSnapshots implements OfferSnapshots
{
    /** @var array<int, OfferSnapshot> */
    private array $snapshots = [];

    /**
     * Dodaje albo podmienia ofertę (np. zmiana ceny, wycofanie).
     */
    public function put(OfferSnapshot $snapshot): void
    {
        $this->snapshots[$snapshot->offerId->value()] = $snapshot;
    }

    public function forOffers(array $offerIds): array
    {
        $found = [];

        foreach ($offerIds as $offerId) {
            if (isset($this->snapshots[$offerId->value()])) {
                $found[$offerId->value()] = $this->snapshots[$offerId->value()];
            }
        }

        return $found;
    }
}
