<?php

namespace SellerPortal\Application;

use SharedKernel\ConcurrencyConflict;

/**
 * Ponawia jednostkę pracy, gdy ktoś zapisał agregat w międzyczasie.
 * DLACZEGO tu, a nie w UI: komendy rezerwacji są systemowe (bez `ExpectedVersion`), więc nikt nie może „odświeżyć strony”. Konflikt wersji jest dla nich normalnym wyścigiem z innymi rezerwacjami, nie błędem użytkownika.
 * Każda próba to świeża transakcja z ponownym odczytem, więc `$work` musi zawierać całe wczytaj → zmień → zapisz.
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
