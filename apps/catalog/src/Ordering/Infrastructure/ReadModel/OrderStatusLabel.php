<?php

namespace Ordering\Infrastructure\ReadModel;

use Ordering\Domain\OrderStatus;

/**
 * Polskie etykiety statusów w jednym miejscu: lista i szczegóły dostają
 * gotowy tekst w propsach, a `OrderStatusBadge.vue` tylko go pokazuje
 * (dobiera kolor po wartości statusu).
 */
final class OrderStatusLabel
{
    public static function for(string $status): string
    {
        return match (OrderStatus::from($status)) {
            OrderStatus::Placed => 'Sprawdzamy dostępność',
            OrderStatus::AwaitingPayment => 'Czeka na płatność',
            OrderStatus::Paid => 'Opłacone',
            OrderStatus::Shipped => 'Wysłane',
            OrderStatus::Rejected => 'Odrzucone',
            OrderStatus::Expired => 'Wygasło',
            OrderStatus::Cancelled => 'Anulowane',
        };
    }
}
