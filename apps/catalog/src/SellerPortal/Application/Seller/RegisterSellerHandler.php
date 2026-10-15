<?php

namespace SellerPortal\Application\Seller;

use SellerPortal\Application\Port\Clock;
use SellerPortal\Application\Port\DomainEventDispatcher;
use SellerPortal\Application\Port\TransactionManager;
use SellerPortal\Application\SellerAlreadyRegistered;
use SellerPortal\Domain\Seller\City;
use SellerPortal\Domain\Seller\Seller;
use SellerPortal\Domain\Seller\SellerId;
use SellerPortal\Domain\Seller\SellerName;
use SellerPortal\Domain\Seller\SellerRepository;
use SellerPortal\Domain\Shared\UserId;

/**
 * Handler komendy RegisterSeller: wczytaj → zmień agregat → zapisz → roześlij zdarzenia, wszystko w jednej transakcji.
 * DLACZEGO zdarzenia lecą wewnątrz transakcji: słuchacze (np. kolejka polityk) wpisują skutki atomowo ze stanem, więc nie ma okna, w którym stan jest zapisany, a reakcja zgubiona. Reguły biznesowe żyją w agregacie, nie tutaj.
 */
final readonly class RegisterSellerHandler
{
    public function __construct(
        private TransactionManager $transactions,
        private Clock $clock,
        private DomainEventDispatcher $events,
        private SellerRepository $sellers,
    ) {}

    public function handle(RegisterSeller $command): SellerId
    {
        return $this->transactions->run(function () use ($command): SellerId {
            $userId = UserId::fromInt($command->userId);

            if ($this->sellers->findByUserId($userId) !== null) {
                throw new SellerAlreadyRegistered;
            }

            $seller = Seller::register($this->sellers->nextIdentity(), $userId, SellerName::fromString($command->name), City::fromString($command->city), $this->clock->now());
            $this->sellers->save($seller);
            $this->events->dispatch($seller->releaseEvents());

            return $seller->id();
        });
    }
}
