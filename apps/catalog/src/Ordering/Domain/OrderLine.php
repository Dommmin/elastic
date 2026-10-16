<?php

namespace Ordering\Domain;

use SharedKernel\Money;

/**
 * Pozycja zamówienia (część agregatu Order) z ceną zamrożoną w chwili złożenia (H5).
 *
 * DLACZEGO cena i nazwa są kopią: późniejsza zmiana ceny w ofercie nie może
 * dotknąć zamówienia, które kupujący już złożył. Niezmienna: rezerwacja daje
 * nowy obiekt (`reserved`), a zmianę zapisuje agregat.
 */
final readonly class OrderLine
{
    private function __construct(
        private OfferId $offerId,
        private ProductName $productName,
        private Money $unitPrice,
        private LineQuantity $quantity,
        private LineStatus $status,
    ) {}

    public static function create(OfferId $offerId, ProductName $productName, Money $unitPrice, LineQuantity $quantity): self
    {
        return new self($offerId, $productName, $unitPrice, $quantity, LineStatus::Pending);
    }

    public static function reconstitute(OfferId $offerId, ProductName $productName, Money $unitPrice, LineQuantity $quantity, LineStatus $status): self
    {
        return new self($offerId, $productName, $unitPrice, $quantity, $status);
    }

    public function reserved(): self
    {
        return new self($this->offerId, $this->productName, $this->unitPrice, $this->quantity, LineStatus::Reserved);
    }

    public function isReserved(): bool
    {
        return $this->status === LineStatus::Reserved;
    }

    public function lineTotal(): Money
    {
        return Money::fromCents($this->unitPrice->cents() * $this->quantity->value(), $this->unitPrice->currency());
    }

    public function offerId(): OfferId
    {
        return $this->offerId;
    }

    public function productName(): ProductName
    {
        return $this->productName;
    }

    public function unitPrice(): Money
    {
        return $this->unitPrice;
    }

    public function quantity(): LineQuantity
    {
        return $this->quantity;
    }

    public function status(): LineStatus
    {
        return $this->status;
    }
}
