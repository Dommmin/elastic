<?php

namespace Tests\Unit\SellerPortal\InMemory;

use SellerPortal\Application\Offer\DraftOffer;
use SellerPortal\Application\Offer\DraftOfferHandler;
use SellerPortal\Application\Offer\SellerOfferAccess;
use SellerPortal\Application\Seller\ApproveSeller;
use SellerPortal\Application\Seller\ApproveSellerHandler;
use SellerPortal\Application\Seller\RegisterSeller;
use SellerPortal\Application\Seller\RegisterSellerHandler;
use SellerPortal\Domain\Offer\OfferId;
use SellerPortal\Domain\Seller\SellerId;

/**
 * Złożony w pamięci portal do testów handlerów: wszystkie porty to
 * dublety, a każdy handler dostaje te same instancje.
 */
final class PortalWorld
{
    public InMemorySellerRepository $sellers;

    public InMemoryOfferRepository $offers;

    public FixedClock $clock;

    public ImmediateTransactionManager $transactions;

    public RecordingDispatcher $dispatcher;

    public FakeProductCatalog $catalog;

    public SellerOfferAccess $access;

    public function __construct()
    {
        $this->sellers = new InMemorySellerRepository;
        $this->offers = new InMemoryOfferRepository;
        $this->clock = new FixedClock;
        $this->transactions = new ImmediateTransactionManager;
        $this->dispatcher = new RecordingDispatcher;
        $this->catalog = new FakeProductCatalog;
        $this->access = new SellerOfferAccess($this->sellers, $this->offers);
    }

    /**
     * Sprzedawca po rejestracji i zatwierdzeniu (status Active).
     */
    public function activeSeller(int $userId): SellerId
    {
        $id = (new RegisterSellerHandler($this->transactions, $this->clock, $this->dispatcher, $this->sellers))
            ->handle(new RegisterSeller($userId, 'Sklep Testowy', 'Poznań'));
        (new ApproveSellerHandler($this->transactions, $this->clock, $this->dispatcher, $this->sellers))
            ->handle(new ApproveSeller($id->value(), 900));

        return $id;
    }

    public function draft(int $userId, int $productId = 1, int $priceCents = 10000, int $stock = 5): OfferId
    {
        return (new DraftOfferHandler($this->transactions, $this->clock, $this->dispatcher, $this->sellers, $this->offers, $this->catalog))
            ->handle(new DraftOffer($userId, $productId, $priceCents, $stock, 'new', 3));
    }

    /**
     * Czyści zapis zdarzeń, żeby test widział tylko to, co zrobił handler pod testem.
     */
    public function forgetDispatched(): void
    {
        $this->dispatcher->dispatched = [];
    }
}
