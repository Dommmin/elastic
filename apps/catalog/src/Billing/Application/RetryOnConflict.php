<?php

namespace Billing\Application;

use SharedKernel\ConcurrencyConflict;

/**
 * Ponawia wczytaj → zmień → zapisz, gdy ktoś zapisał konto w międzyczasie
 * (płatność kontra sweep zaległości). Kopia wzorca z SellerPortal i Ordering:
 * konteksty nie importują od siebie kodu aplikacji.
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
