<?php

namespace SellerPortal\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Jedno miejsce na format czasu w repozytoriach.
 *
 * DLACZEGO strefa aplikacji i mikrosekundy: tak zapisuje Eloquent (model
 * `Outbox` ma `Y-m-d H:i:s.u`), więc wiersze z obu dróg są porównywalne,
 * a limit zmian ceny nie gubi sekundowych różnic przy porównaniu w SQL.
 */
final class SqlTimestamp
{
    public static function format(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone(config('app.timezone')))->format('Y-m-d H:i:s.u');
    }

    public static function parse(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value);
    }
}
