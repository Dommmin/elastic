<?php

namespace Ordering\Infrastructure\Persistence;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionProperty;
use SellerPortal\Infrastructure\Persistence\SqlTimestamp;
use SharedKernel\DomainEvent;
use SharedKernel\Money;

/**
 * Dziennik zdarzeń zamówienia (`order_events`) pod oś czasu kupującego.
 *
 * DLACZEGO osobno od outboxu: zdarzenia Order nie opuszczają kontekstu, więc
 * nie mają kontraktu Published Language. Typ i payload wynikają z klasy
 * zdarzenia, żeby nowe zdarzenie trafiało do dziennika bez dopisywania mapy.
 */
final class OrderEventLog
{
    /**
     * @param  list<DomainEvent>  $events
     */
    public static function record(int $orderId, array $events): void
    {
        foreach ($events as $event) {
            DB::table('order_events')->insert([
                'order_id' => $orderId,
                'event_type' => self::type($event),
                'payload' => json_encode(self::payload($event), JSON_THROW_ON_ERROR),
                'occurred_at' => SqlTimestamp::format($event->occurredAt()),
            ]);
        }
    }

    /**
     * `OrderPlaced` → `order.placed`, `RefundRequested` → `order.refund_requested`.
     */
    public static function type(DomainEvent $event): string
    {
        $name = (new ReflectionClass($event))->getShortName();

        return 'order.'.Str::snake(preg_replace('/^Order(?=[A-Z])/', '', $name));
    }

    /**
     * @return array<string, scalar|null>
     */
    public static function payload(DomainEvent $event): array
    {
        $payload = [];

        foreach ((new ReflectionClass($event))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            // Czas zdarzenia ma własną kolumnę.
            if ($property->getName() === 'occurredAt') {
                continue;
            }

            $key = Str::snake($property->getName());
            $value = $property->getValue($event);

            if ($value instanceof Money) {
                $payload[$key.'_cents'] = $value->cents();
                $payload['currency'] = $value->currency()->value;
            } else {
                $payload[$key] = self::scalar($value);
            }
        }

        return $payload;
    }

    private static function scalar(mixed $value): int|string|null
    {
        return match (true) {
            $value === null => null,
            $value instanceof DateTimeImmutable => $value->format(DateTimeInterface::ATOM),
            $value instanceof BackedEnum => $value->value,
            is_object($value) && method_exists($value, 'value') => $value->value(),
            default => $value,
        };
    }
}
