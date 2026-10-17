<?php

namespace Ordering\Application;

use SharedKernel\ConcurrencyConflict;

/**
 * Ponawia jednostkę pracy, gdy ktoś zapisał zamówienie w międzyczasie
 * (webhook kontra sweep, saga kontra kupujący: R3, R1).
 *
 * DLACZEGO kopia `SellerPortal\Application\RetryOnConflict`, a nie import:
 * `Ordering\Application` nie zna portalu (test architektury). To kilkanaście
 * linii bez reguł biznesowych, więc duplikat jest tańszy niż trzeci moduł
 * „wspólny” albo przeniesienie do SharedKernel, który ma zostać mały.
 * Każda próba to świeża transakcja z ponownym odczytem, więc `$work` musi
 * zawierać całe wczytaj → zmień → zapisz.
 */
final class RetryOnConflict
{
    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     *
     * @throws ConcurrencyConflict po wyczerpaniu prób
     */
    public static function run(callable $work, int $attempts = 3): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $work();
            } catch (ConcurrencyConflict $e) {
                if ($attempt >= $attempts) {
                    throw $e;
                }
            }
        }
    }
}
