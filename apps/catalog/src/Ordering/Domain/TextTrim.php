<?php

namespace Ordering\Domain;

use SharedKernel\InvalidValue;

/**
 * Przycinanie tekstu wpisanego przez człowieka (nazwa, numer przesyłki, powód).
 *
 * DLACZEGO nie `trim()`: nie zna twardej spacji (U+00A0) ani znaków
 * niewidocznych, więc napis złożony z samych takich znaków uchodziłby za
 * niepusty (ta sama reguła co w `Reason` portalu; kodu nie współdzielimy,
 * bo Ordering nie importuje portalu).
 *
 * @internal tylko dla value objectów tej domeny
 */
final class TextTrim
{
    /**
     * @throws InvalidValue gdy tekst nie jest poprawnym UTF-8
     */
    public static function trim(string $value): string
    {
        return preg_replace('/^[\p{Z}\p{C}]+|[\p{Z}\p{C}]+$/u', '', $value)
            ?? throw new InvalidValue('Tekst musi być poprawnym UTF-8.');
    }
}
