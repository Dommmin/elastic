<?php

namespace Billing\Application\Port;

use DateTimeImmutable;

/**
 * Port zegara: handlery pytają go o "teraz", więc testy mogą je zamrozić.
 */
interface Clock
{
    public function now(): DateTimeImmutable;
}
