<?php

namespace SellerPortal\Infrastructure\Job;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use SellerPortal\Application\Policy\ReinstateOffersOfUnblockedSeller;
use SellerPortal\Domain\Seller\SellerId;

/**
 * Job workera: przywróć oferty odblokowanego sprzedawcy.
 *
 * DLACZEGO w jobie tylko `int`: payload leży w tabeli `jobs` i musi przeżyć
 * zmiany kodu; serwis i repozytoria wyciągamy z kontenera dopiero w `handle()`.
 * Powtórzenia są bezpieczne, bo polityka jest idempotentna.
 */
final class ReinstateOffersOfUnblockedSellerJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** Sprzedawca z tysiącami ofert: domyślne 60 s workera nie wystarcza (musi być < retry_after). */
    public int $timeout = 600;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public function __construct(public readonly int $sellerId) {}

    public function handle(ReinstateOffersOfUnblockedSeller $policy): void
    {
        $policy->run(SellerId::fromInt($this->sellerId));
    }
}
