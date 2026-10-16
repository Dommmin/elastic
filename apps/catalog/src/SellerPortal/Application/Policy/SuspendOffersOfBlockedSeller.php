<?php

namespace SellerPortal\Application\Policy;

use SellerPortal\Application\Port\Clock;
use SellerPortal\Application\Port\DomainEventDispatcher;
use SellerPortal\Application\Port\TransactionManager;
use SellerPortal\Domain\Offer\OfferRepository;
use SellerPortal\Domain\Offer\OfferStatus;
use SellerPortal\Domain\Offer\SuspensionReason;
use SellerPortal\Domain\Seller\SellerId;
use SellerPortal\Domain\Seller\SellerRepository;
use SellerPortal\Domain\Seller\SellerStatus;
use SharedKernel\ConcurrencyConflict;

/**
 * Wykonanie polityki „zablokowany sprzedawca nie sprzedaje”: zawiesza jego opublikowane oferty.
 *
 * DLACZEGO stan sprzedawcy sprawdzamy dopiero tutaj: job powstał przy blokadzie, ale
 * wykonuje się później. Jeśli moderator zdążył odblokować, zawieszanie byłoby błędem,
 * więc kończymy bez działania (szybkie „zablokuj → odblokuj” nic nie zostawia).
 * DLACZEGO transakcja na ofertę i paczki po 500: sprzedawca może mieć tysiące ofert;
 * jedna wielka transakcja trzymałaby blokady i rosła bez końca, a błąd jednej oferty
 * cofałby wszystkie. Idempotencja: oferty już nie-Published są pomijane, więc
 * ponowienie joba (tries/backoff) nie robi szkody ani nie dubluje zdarzeń outboxu.
 */
final readonly class SuspendOffersOfBlockedSeller
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
     * @return int liczba faktycznie zawieszonych ofert
     */
    public function run(SellerId $sellerId): int
    {
        if ($this->sellers->get($sellerId)->status() !== SellerStatus::Blocked) {
            return 0;
        }

        $suspended = 0;
        $afterId = 0;

        while ($ids = $this->offers->idsForSellerPolicy($sellerId, OfferStatus::Published, null, $afterId, self::BATCH_SIZE)) {
            foreach ($ids as $id) {
                $afterId = $id->value();

                for ($attempt = 1; ; $attempt++) {
                    try {
                        $suspended += $this->transactions->run(function () use ($id): int {
                            $offer = $this->offers->get($id);

                            if ($offer->status() !== OfferStatus::Published) {
                                return 0;
                            }

                            $offer->suspend(SuspensionReason::sellerBlocked(), $this->clock->now());
                            $this->offers->save($offer);
                            $this->events->dispatch($offer->releaseEvents());

                            return 1;
                        });

                        break;
                    } catch (ConcurrencyConflict $e) {
                        // Po wyczerpaniu prób oddajemy błąd jobowi: ponowi się cały (idempotentnie).
                        if ($attempt >= self::ATTEMPTS_PER_OFFER) {
                            throw $e;
                        }
                    }
                }
            }
        }

        return $suspended;
    }
}
