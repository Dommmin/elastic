<?php

namespace SellerPortal\Application\Policy;

use SellerPortal\Application\Port\Clock;
use SellerPortal\Application\Port\DomainEventDispatcher;
use SellerPortal\Application\Port\TransactionManager;
use SellerPortal\Domain\Offer\OfferRepository;
use SellerPortal\Domain\Offer\OfferStatus;
use SellerPortal\Domain\Offer\SuspensionKind;
use SellerPortal\Domain\Seller\SellerId;
use SellerPortal\Domain\Seller\SellerRepository;
use SellerPortal\Domain\Seller\SellerStatus;
use SellerPortal\Domain\Shared\ConcurrencyConflict;

/**
 * Odwrotność `SuspendOffersOfBlockedSeller`: po odblokowaniu przywraca oferty.
 *
 * DLACZEGO tylko `SuspensionKind::SellerBlocked`: oferta, którą moderator
 * zawiesił ręcznie (np. za podejrzaną cenę), ma zostać zawieszona mimo
 * odblokowania konta. Ten sam kształt co zawieszanie: sprawdzenie stanu
 * sprzedawcy w momencie wykonania, paczki po 500, transakcja na ofertę,
 * 3 próby przy konflikcie wersji, idempotencja.
 */
final readonly class ReinstateOffersOfUnblockedSeller
{
    public const BATCH_SIZE = 500;

    public const ATTEMPTS_PER_OFFER = 3;

    public function __construct(
        private TransactionManager $transactions,
        private Clock $clock,
        private DomainEventDispatcher $events,
        private SellerRepository $sellers,
        private OfferRepository $offers,
    ) {}

    /**
     * @return int liczba faktycznie przywróconych ofert
     */
    public function run(SellerId $sellerId): int
    {
        if ($this->sellers->get($sellerId)->status() !== SellerStatus::Active) {
            return 0;
        }

        $reinstated = 0;
        $afterId = 0;

        while ($ids = $this->offers->idsForSellerPolicy($sellerId, OfferStatus::Suspended, SuspensionKind::SellerBlocked, $afterId, self::BATCH_SIZE)) {
            foreach ($ids as $id) {
                $afterId = $id->value();

                for ($attempt = 1; ; $attempt++) {
                    try {
                        $reinstated += $this->transactions->run(function () use ($id): int {
                            $offer = $this->offers->get($id);

                            if ($offer->status() !== OfferStatus::Suspended || $offer->suspension()?->kind() !== SuspensionKind::SellerBlocked) {
                                return 0;
                            }

                            $offer->reinstate($this->clock->now());
                            $this->offers->save($offer);
                            $this->events->dispatch($offer->releaseEvents());

                            return 1;
                        });

                        break;
                    } catch (ConcurrencyConflict $e) {
                        if ($attempt >= self::ATTEMPTS_PER_OFFER) {
                            throw $e;
                        }
                    }
                }
            }
        }

        return $reinstated;
    }
}
