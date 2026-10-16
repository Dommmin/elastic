<?php

namespace SharedKernel;

/**
 * Wartość spoza dozwolonego zakresu value objectu (np. cena <= 0).
 *
 * DLACZEGO jeden wyjątek dla wszystkich VO: walidacja formatu to sprawa
 * UI, a VO pilnuje tylko niezmiennika "nie da się utworzyć nieprawidłowej
 * wartości". Konkretny powód trafia do komunikatu, a UI i tak mapuje
 * wyłącznie `CODE`.
 */
final class InvalidValue extends DomainException
{
    public const string CODE = 'invalid_value';
}
