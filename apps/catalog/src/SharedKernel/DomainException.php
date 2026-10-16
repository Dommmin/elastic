<?php

namespace SharedKernel;

/**
 * Wspólny rodzic wyjątków łamiących regułę biznesową.
 *
 * DLACZEGO każdy potomek ma stały `CODE` (snake_case): komunikat dla
 * człowieka należy do warstwy UI (po polsku, może się zmienić), a domena
 * zwraca tylko stabilny identyfikator reguły. Dzięki temu tekst można
 * poprawić bez dotykania domeny, a testy nie łamią się na literówkach.
 *
 * Dziedziczymy po `\DomainException` z SPL, żeby nie wymyślać hierarchii od
 * zera, a jednocześnie móc łapać "wszystkie błędy domeny" jednym `catch`.
 */
abstract class DomainException extends \DomainException {}
