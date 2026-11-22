<?php

namespace App\Seller360\Simulation;

use App\Analytics\Simulation\SimulatedEventPublisher;
use App\Support\Outbox\EventEnvelope;
use DateTimeImmutable;
use LogicException;
use Random\Randomizer;

/**
 * Zdarzenia sprzedaży v2 z datą wsteczną (`user.added_to_cart` z `seller_id`, `user.checked_out`
 * z `seller_amounts`), publikowane przez `SimulatedEventPublisher` z ETAPU 12 prosto do
 * `marketplace.events`. Search zapisuje je do `events-seller-default`, transform `seller-sales-daily`
 * (synchronizacja po `event.ingested`, więc wsteczny `@timestamp` jest w porządku) sumuje sesje dziennie,
 * a `seller360:sales:refresh` liczy konwersję w oknach [now-30d, now) i [now-60d, now-30d).
 *
 * Sesja koszyka = jedno `added_to_cart`; sesja zamówienia = ta sama sesja z `checked_out` (podzbiór).
 * ID sesji unikalne per sprzedawca i okno. Zdarzenia leżą 33–57 dni temu (okno poprzednie) i 3–27 dni
 * temu (bieżące): kilka dni zapasu od granic okien, bo przeliczenie biegnie później niż generator.
 *
 * Profile: `planted` — spadek konwersji w [0,3; 0,5] przy ≥ 40 sesjach koszyka w poprzednim oknie;
 * `stable40` (bliskie pudło „drop”) i `stable` (wszyscy inni) — konwersja bieżąca ≥ poprzedniej,
 * więc spadek ≤ 0 (< 0,1 z zapasem); `stable40` ma też ≥ 40 sesji w poprzednim oknie.
 */
final class SalesEvents
{
    public const PROFILE_PLANTED = 'planted';

    public const PROFILE_STABLE = 'stable';

    public const PROFILE_STABLE_40 = 'stable40';

    public const DROP_MIN = 0.3;

    public const DROP_MAX = 0.5;

    private const WINDOWS = ['prev' => [33, 57], 'cur' => [3, 27]];

    /** Fałszywi kupujący — poza zakresem prawdziwych ID i puli `analytics:simulate` (900M+). */
    private const USER_ID_BASE = 910_000_000;

    private const USER_POOL = 5_000;

    private const MAX_ATTEMPTS = 50;

    /**
     * @return array{prev: array{carts: int, orders: int}, cur: array{carts: int, orders: int}}
     */
    public static function draw(Randomizer $random, string $profile): array
    {
        if ($profile === self::PROFILE_PLANTED) {
            return self::drawPlanted($random);
        }

        $prevCarts = $profile === self::PROFILE_STABLE_40 ? $random->getInt(40, 60) : $random->getInt(5, 40);
        $prevOrders = min($prevCarts, max(1, (int) round($prevCarts * $random->getFloat(0.1, 0.4))));
        $curCarts = $random->getInt(5, 40);
        // Sufit całkowitoliczbowy: cur ≥ prev dokładnie, bez błędów zaokrągleń floatów.
        $curOrders = min($curCarts, intdiv($curCarts * $prevOrders + $prevCarts - 1, $prevCarts) + $random->getInt(0, 1));

        return ['prev' => ['carts' => $prevCarts, 'orders' => $prevOrders], 'cur' => ['carts' => $curCarts, 'orders' => $curOrders]];
    }

    /**
     * @return array{prev: array{carts: int, orders: int}, cur: array{carts: int, orders: int}}
     */
    private static function drawPlanted(Randomizer $random): array
    {
        $prevCarts = $random->getInt(40, 60);
        $prevOrders = (int) round($prevCarts * $random->getFloat(0.3, 0.5));
        $curCarts = $random->getInt(40, 60);
        $prev = $prevOrders / $prevCarts;

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $curOrders = (int) round($curCarts * $prev * (1 - $random->getFloat(0.35, 0.45)));
            $drop = ($prev - $curOrders / $curCarts) / $prev;
            if ($drop >= self::DROP_MIN && $drop <= self::DROP_MAX) {
                return ['prev' => ['carts' => $prevCarts, 'orders' => $prevOrders], 'cur' => ['carts' => $curCarts, 'orders' => $curOrders]];
            }
        }

        throw new LogicException('Nie udało się dobrać spadku konwersji w [0,3; 0,5].');
    }

    /**
     * @param  array{prev: array{carts: int, orders: int}, cur: array{carts: int, orders: int}}  $plan
     * @return int liczba opublikowanych zdarzeń
     */
    public static function publish(SimulatedEventPublisher $publisher, Randomizer $random, int $sellerId, array $plan, DateTimeImmutable $now): int
    {
        $today = $now->setTime(0, 0);
        $events = 0;

        foreach (self::WINDOWS as $window => [$minDays, $maxDays]) {
            for ($k = 0; $k < $plan[$window]['carts']; $k++) {
                $sessionId = "sim360-{$sellerId}-{$window}-{$k}";
                $userId = self::USER_ID_BASE + $random->getInt(1, self::USER_POOL);
                $cartAt = $today->modify('-'.$random->getInt($minDays, $maxDays).' days')->modify('+'.$random->getInt(0, 86_399).' seconds');

                $publisher->publish(EventEnvelope::make(
                    DeterministicUlid::make($cartAt, $random), 'user.added_to_cart', $cartAt, 'session', $sessionId, 0,
                    ['seller_id' => $sellerId, 'user_id' => $userId],
                    version: 2,
                ));
                $events++;

                if ($k < $plan[$window]['orders']) {
                    $orderAt = $cartAt->modify('+'.$random->getInt(60, 900).' seconds');
                    $amount = $random->getInt(2_000, 50_000);
                    $publisher->publish(EventEnvelope::make(
                        DeterministicUlid::make($orderAt, $random), 'user.checked_out', $orderAt, 'session', $sessionId, 0,
                        ['order_ids' => [], 'amount_cents' => $amount, 'user_id' => $userId, 'seller_amounts' => [(string) $sellerId => $amount]],
                        version: 2,
                    ));
                    $events++;
                }
            }
        }

        return $events;
    }
}
