<?php

namespace SellerPortal\Application\Seller;

use SellerPortal\Application\Port\Clock;
use SellerPortal\Application\Port\DomainEventDispatcher;
use SellerPortal\Application\Port\TransactionManager;
use SellerPortal\Domain\Seller\SellerId;
use SellerPortal\Domain\Seller\SellerRepository;
use SellerPortal\Domain\Shared\ModeratorId;
use SellerPortal\Domain\Shared\Reason;

/**
 * Handler komendy RejectSeller: wczytaj → zmień agregat → zapisz → roześlij zdarzenia, wszystko w jednej transakcji.
 * DLACZEGO zdarzenia lecą wewnątrz transakcji: słuchacze (np. kolejka polityk) wpisują skutki atomowo ze stanem, więc nie ma okna, w którym stan jest zapisany, a reakcja zgubiona. Reguły biznesowe żyją w agregacie, nie tutaj.
 */
final readonly class RejectSellerHandler
{
    public function __construct(
        private TransactionManager $transactions,
        private Clock $clock,
        private DomainEventDispatcher $events,
        private SellerRepository $sellers,
    ) {}

    public function handle(RejectSeller $command): void
    {
        $this->transactions->run(function () use ($command): void {
            $seller = $this->sellers->get(SellerId::fromInt($command->sellerId));
            $seller->reject(ModeratorId::fromInt($command->moderatorId), Reason::fromString($command->reason), $this->clock->now());
            $this->sellers->save($seller);
            $this->events->dispatch($seller->releaseEvents());
        });
    }
}
