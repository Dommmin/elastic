<?php

namespace App\FakePaymentGateway;

use RuntimeException;

/**
 * Kupujący próbuje zapłacić w sesji bramki po jej terminie.
 *
 * DLACZEGO `RuntimeException`, a nie `SharedKernel\DomainException`: bramka
 * jest zewnętrznym dostawcą, poza modelem domeny marketplace'u. Kontroler
 * bramki tłumaczy wyjątek na komunikat sam.
 */
final class SessionExpired extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Sesja płatności wygasła.');
    }
}
