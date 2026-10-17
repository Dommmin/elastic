<?php

namespace SellerPortal\Domain\Offer;

use DateTimeImmutable;
use SharedKernel\Money;

/**
 * Reguły strażnika ceny: próg 30% i limit 5 zmian w kroczącym oknie 24 h.
 *
 * DLACZEGO osobna klasa, a nie metody w Offer: progi to polityka biznesowa,
 * która będzie się zmieniać niezależnie od cyklu życia oferty, i da się ją
 * testować bez budowania agregatu. Jest czysta — zero stanu, `$now` z zewnątrz.
 *
 * DLACZEGO okno otwarte z lewej (`changedAt > now − 24h`): zmiana sprzed
 * dokładnie 24 h już "wygasła", więc granica jest jednoznaczna i nie zależy
 * od tego, czy porównujemy `>` czy `>=` w kilku miejscach.
 */
final class PriceChangePolicy
{
    public const int THRESHOLD_PERCENT = 30;

    public const int MAX_CHANGES_PER_DAY = 5;

    /**
     * Limit sprawdzamy przed progiem: wyczerpany limit blokuje także zmiany,
     * które trafiłyby do moderatora (liczą się do limitu tak samo).
     *
     * @param  list<DateTimeImmutable>  $recent
     *
     * @throws PriceChangeLimitExceeded
     */
    public static function decide(Money $current, Money $proposed, array $recent, DateTimeImmutable $now): PriceChangeDecision
    {
        if (self::changesLeft($recent, $now) === 0) {
            throw new PriceChangeLimitExceeded;
        }

        // Punkt odniesienia to ZAWSZE obecna cena: kolejność argumentów zmienia wynik.
        return $current->differsByMoreThanPercent($proposed, self::THRESHOLD_PERCENT)
            ? PriceChangeDecision::AwaitingApproval
            : PriceChangeDecision::AppliedNow;
    }

    /**
     * @param  list<DateTimeImmutable>  $recent
     */
    public static function changesLeft(array $recent, DateTimeImmutable $now): int
    {
        return max(0, self::MAX_CHANGES_PER_DAY - count(self::withinWindow($recent, $now)));
    }

    /**
     * @param  list<DateTimeImmutable>  $recent
     * @return list<DateTimeImmutable>
     */
    public static function withinWindow(array $recent, DateTimeImmutable $now): array
    {
        $since = $now->modify('-24 hours');

        return array_values(array_filter(
            $recent,
            static fn (DateTimeImmutable $changedAt): bool => $changedAt > $since,
        ));
    }
}
