<?php

namespace SellerPortal\Application\Port;

use DateTimeImmutable;

/**
 * Port zegara: warstwa aplikacji i repozytoria nie wołają `now()` same,
 * tylko pytają ten port. Dzięki temu "teraz" można w testach zamrozić
 * (`Carbon::setTestNow`), a domena nadal dostaje czas jako argument.
 */
interface Clock
{
    public function now(): DateTimeImmutable;
}
