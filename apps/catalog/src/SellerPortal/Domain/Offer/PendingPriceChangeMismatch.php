<?php

namespace SellerPortal\Domain\Offer;

use SharedKernel\DomainException;

/**
 * Moderator decyduje o innej cenie niż ta, która czeka teraz w ofercie
 * (sprzedawca w międzyczasie anulował zgłoszenie i zgłosił nowe).
 *
 * DLACZEGO reguła domeny, a nie tylko wersja oferty: moderator akceptuje
 * konkretną kwotę, nie „cokolwiek tam teraz wisi”. Bez tego sprawdzenia
 * kliknięcie „Akceptuj” przy 131 zł mogłoby wprowadzić 900 zł — obejście
 * strażnika ceny jednym kliknięciem. Wersja wykrywa każdą zmianę oferty,
 * ta reguła mówi, CO się zmieniło i dlaczego decyzja jest nieważna.
 */
final class PendingPriceChangeMismatch extends DomainException
{
    public const string CODE = 'pending_price_changed';

    public function __construct(string $message = 'Zgłoszona cena różni się od tej, o której decyduje moderator.')
    {
        parent::__construct($message);
    }
}
