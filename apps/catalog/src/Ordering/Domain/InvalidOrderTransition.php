<?php

namespace Ordering\Domain;

use SharedKernel\DomainException;

/**
 * Operacja niedozwolona w bieżącym statusie zamówienia (np. wysyłka nieopłaconego).
 *
 * DLACZEGO akcja to `OrderAction|string`: część przejść woła saga, nie UI
 * (`mark_line_reserved`, `reject`, `mark_refund_completed`). Nie dokładamy
 * ich do `OrderAction`, bo ten enum to lista przycisków dla ludzi.
 */
final class InvalidOrderTransition extends DomainException
{
    public const string CODE = 'invalid_order_transition';

    public static function from(OrderStatus $status, OrderAction|string $action): self
    {
        $name = $action instanceof OrderAction ? $action->value : $action;

        return new self("Akcja „{$name}” jest niedozwolona dla zamówienia w stanie „{$status->value}”.");
    }
}
