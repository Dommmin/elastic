<?php

namespace Ordering\Domain;

use SharedKernel\InvalidValue;

/**
 * Nazwa produktu zamrożona w pozycji w chwili złożenia. DLACZEGO kopia, a nie odczyt z katalogu: zamówienie ma pokazywać to, co kupujący kupił, nawet gdy produkt później zmieni nazwę.
 */
final readonly class ProductName
{
    private const int MIN_LENGTH = 1;

    private const int MAX_LENGTH = 255;

    private function __construct(private string $value) {}

    public static function fromString(string $value): self
    {
        $trimmed = TextTrim::trim($value);
        $length = mb_strlen($trimmed, 'UTF-8');

        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            throw new InvalidValue('Nazwa produktu musi mieć od '.self::MIN_LENGTH.' do '.self::MAX_LENGTH.' znaków.');
        }

        return new self($trimmed);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
