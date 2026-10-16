<?php

namespace Ordering\Application\Port;

use DateTimeImmutable;

/**
 * Port zegara Orderingu. DLACZEGO własny, a nie `SellerPortal\Application\Port\Clock`:
 * Ordering nie importuje portalu; adapter może być ten sam, port nie.
 * Domena dostaje czas jako argument, a w testach „teraz” jest zamrożone.
 */
interface Clock
{
    public function now(): DateTimeImmutable;
}
