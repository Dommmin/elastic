<?php

namespace App\Security;

use App\Message\IntegrationEvent;

/**
 * Koperta `offer.price_changed` / `review.created` -> dokument data streamu `events-security-default`
 * (ETAP 15). Nazwy pól muszą się zgadzać z szablonem infra/elasticsearch/detection/template-events-security.json
 * (`dynamic: strict`). `event.ingested` i `product.popularity` dokłada pipeline `events-security`.
 * `trace.id` pomijamy z tego samego powodu co w analityce (handler partii nie widzi stampów).
 */
class SecurityDocumentMapper
{
    public const STREAM = 'events-security-default';

    /**
     * @return list<array{index: string, id: string, doc: array<string, mixed>}>
     */
    public function map(IntegrationEvent $event): array
    {
        $doc = match ($event->type) {
            'offer.price_changed' => $this->priceChanged($event),
            'review.created' => $this->reviewCreated($event),
            default => null,
        };
        if ($doc === null) {
            return [];
        }

        $doc = [
            '@timestamp' => $event->occurredAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.vP'),
            'event' => ['id' => $event->id, 'type' => $event->type],
            ...$doc,
        ];
        // Tylko `true` oznacza zdarzenie z symulatora; fałsz i brak to ten sam przypadek (brak klucza).
        if (($event->data['synthetic'] ?? null) === true) {
            $doc['labels'] = ['synthetic' => true];
        }

        return [['index' => self::STREAM, 'id' => $event->id, 'doc' => $doc]];
    }

    /** @return array<string, mixed> */
    private function priceChanged(IntegrationEvent $event): array
    {
        $d = $event->data;
        $old = self::int($d, 'old_price_cents');
        $new = self::int($d, 'new_price_cents');

        $offer = ['id' => $event->aggregateId, 'old_price_cents' => $old, 'new_price_cents' => $new];
        // Stara cena 0 (lub ujemna): zmiany procentowej nie ma, a zdarzenie i tak zapisujemy.
        if ($old > 0) {
            $offer['change_pct'] = round(($new - $old) / $old * 100, 2);
        }

        return [
            'offer' => $offer,
            'product' => ['id' => self::string($d, 'product_id')],
            'seller' => ['id' => self::string($d, 'seller_id')],
        ];
    }

    /** @return array<string, mixed> */
    private function reviewCreated(IntegrationEvent $event): array
    {
        $d = $event->data;

        $user = ['id' => self::string($d, 'user_id')];
        if (isset($d['user_account_age_hours'])) {
            $user['account_age_hours'] = self::int($d, 'user_account_age_hours');
        }

        $doc = [
            'review' => ['id' => self::string($d, 'id'), 'rating' => self::int($d, 'rating')],
            'product' => ['id' => self::string($d, 'product_id')],
            'user' => $user,
        ];

        // Seeder i recenzje sprzed ETAPU 15 nie mają odcisku klienta: wtedy bez kluczy `client` i `user_agent`.
        $client = array_filter([
            'ip_hash' => isset($d['client_ip_hash']) ? self::string($d, 'client_ip_hash') : null,
            'ip_prefix' => isset($d['client_ip_prefix']) ? self::string($d, 'client_ip_prefix') : null,
        ], static fn (?string $v) => $v !== null);
        if ($client !== []) {
            $doc['client'] = $client;
        }
        if (isset($d['client_user_agent'])) {
            $doc['user_agent'] = ['original' => self::string($d, 'client_user_agent')];
        }

        return $doc;
    }

    /** @param array<string, mixed> $d */
    private static function string(array $d, string $key): string
    {
        $v = $d[$key] ?? null;
        if (! \is_string($v) && ! \is_int($v)) {
            throw new \InvalidArgumentException("Pole `{$key}` musi być tekstem lub liczbą całkowitą.");
        }

        return (string) $v;
    }

    /** @param array<string, mixed> $d */
    private static function int(array $d, string $key): int
    {
        $v = $d[$key] ?? null;
        if (\is_int($v)) {
            return $v;
        }
        if (\is_string($v) && preg_match('/^-?\d+$/', $v) === 1) {
            return (int) $v;
        }

        throw new \InvalidArgumentException("Pole `{$key}` musi być liczbą całkowitą.");
    }
}
