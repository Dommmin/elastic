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

    /**
     * Czas w ładunku zdarzenia integracyjnego: ISO 8601 z offsetem i mikrosekundami (`2026-11-21T12:00:00.000000+00:00`).
     * DLACZEGO nie `format()`: postać SQL nie ma strefy, więc konsument z inną strefą domyślną odczytałby inny moment.
     */
    public static function iso(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone(config('app.timezone')))->format('Y-m-d\TH:i:s.uP');
    }

    public static function parse(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value);
    }
}
