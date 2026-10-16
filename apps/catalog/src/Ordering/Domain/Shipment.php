<?php

namespace Ordering\Domain;

use DateTimeImmutable;

/**
 * Wysyłka opłaconego zamówienia: numer przesyłki od sprzedawcy i chwila nadania.
 */
final readonly class Shipment
{
    public function __construct(
        public TrackingNumber $trackingNumber,
        public DateTimeImmutable $shippedAt,
    ) {}
}
