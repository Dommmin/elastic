<?php

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Detektor nadużyć uruchamiany przez `security:detect` i job `security-detect` (ETAP 15):
 * liczy alerty za zakres `[from, to)` z historii zdarzeń. Zapis (idempotentny po `Alert::id()`)
 * robi wołający, nie detektor.
 */
#[AutoconfigureTag('app.security_detector')]
interface Detector
{
    /** Identyfikator reguły (`rule.id`), po nim `security:detect --rule=` wybiera detektor. */
    public function id(): string;

    /** @return list<Alert> */
    public function detect(\DateTimeImmutable $from, \DateTimeImmutable $to): array;
}
