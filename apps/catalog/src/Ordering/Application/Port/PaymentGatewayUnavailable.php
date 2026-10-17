<?php

namespace Ordering\Application\Port;

use RuntimeException;

/**
 * Bramka płatności nie odpowiedziała albo odmówiła technicznie.
 *
 * DLACZEGO `RuntimeException`, a nie `SharedKernel\DomainException`: to awaria
 * infrastruktury, nie złamana reguła biznesowa. Nie ma dla niej komunikatu
 * w UI ani `CODE`; job zwrotu ma ją przepuścić i spróbować ponownie.
 */
final class PaymentGatewayUnavailable extends RuntimeException
{
    public function __construct(string $message = 'Bramka płatności jest niedostępna.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
