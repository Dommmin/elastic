<?php

namespace Ordering\Application\Process;

use Ordering\Domain\OrderId;

/**
 * Klucz rezerwacji, którym zamówienie podpisuje się w ofertach portalu.
 *
 * DLACZEGO jeden klucz na zamówienie (a nie na pozycję): oferta trzyma
 * rezerwacje po kluczu, a zamówienie ma każdą ofertę najwyżej raz, więc
 * para (klucz, oferta) jest jednoznaczna. Deterministyczny klucz to warunek
 * idempotencji: ponowiony krok sagi trafia w tę samą rezerwację (R2, H10).
 */
final class OrderReservationKey
{
    public static function for(OrderId $orderId): string
    {
        return 'order-'.$orderId->value();
    }
}
