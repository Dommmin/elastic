<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ślad konfliktu wersji (optymistyczna blokada ofert i zamówień) dla
 * `orders:race-test`: ponowienia (`RetryOnConflict`) połykają konflikty
 * w innych procesach, więc bez śladu nie da się ich policzyć.
 *
 * DLACZEGO domyślnie wyłączony (kanał `concurrency` = null): to
 * instrumentacja pomiaru, nie log produkcyjny; zwykły ruch nie ma czego
 * tu dopisywać ani czego rotować.
 * DLACZEGO ścieżka z `getenv()` przy każdym wywołaniu, a nie tylko
 * z konfiguracji: kontener robi `config:cache` przy starcie, więc `env()`
 * w config/logging.php jest zamrożone; zmienna środowiskowa ustawiona
 * procesom potomnym przez `orders:race-test` musi zadziałać mimo to.
 * DLACZEGO połykamy każdy błąd: zapis śladu stoi tuż przed
 * `throw new ConcurrencyConflict`; niezapisywalny plik nie może podmienić
 * wyjątku domeny na inny, bo wtedy ponowienia przestałyby działać.
 */
final class ConcurrencyConflictTrace
{
    public const string ENV = 'LOG_CONCURRENCY_PATH';

    public static function record(string $aggregate, int $id, int $version): void
    {
        try {
            $path = getenv(self::ENV);
            $logger = is_string($path) && $path !== ''
                ? Log::build(['driver' => 'single', 'path' => $path, 'level' => 'info', 'replace_placeholders' => true])
                : Log::channel('concurrency');

            $logger->info('concurrency_conflict', ['aggregate' => $aggregate, 'id' => $id, 'version' => $version]);
        } catch (Throwable) {
            // Patrz docblock: ślad nigdy nie maskuje konfliktu.
        }
    }
}
