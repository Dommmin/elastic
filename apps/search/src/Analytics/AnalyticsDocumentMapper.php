<?php

namespace App\Analytics;

use App\Message\IntegrationEvent;

/**
 * Koperta `user.*` -> dokumenty data streamów (ETAP 12, spec sekcja 4-5).
 *
 * Nazwy pól muszą się zgadzać z szablonami w infra/elasticsearch/analytics/
 * (`dynamic: strict` — obce pole to odrzucenie całego dokumentu). `event.ingested`
 * ustawia pipeline `events-ingested`, nie ten kod. `trace.id` pomijamy: ślad
 * niesie stamp koperty Messengera, a handler partii dostaje samą wiadomość.
 */
class AnalyticsDocumentMapper
{
    public const EVENTS_STREAM = 'events-user-default';
    public const IMPRESSIONS_STREAM = 'events-impression-default';

    private const TYPES = ['user.searched', 'user.clicked_result', 'user.added_to_cart', 'user.checked_out'];

    /**
     * @return list<array{index: string, id: string, doc: array<string, mixed>}>
     */
    public function map(IntegrationEvent $event): array
    {
        if (! \in_array($event->type, self::TYPES, true)) {
            return [];
        }

        $timestamp = $event->occurredAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.vP');
        $data = $event->data;

        $doc = [
            '@timestamp' => $timestamp,
            'event' => ['id' => $event->id, 'type' => $event->type],
            'session' => ['id' => $event->aggregateId],
        ];
        if (isset($data['user_id'])) {
            $doc['user'] = ['id' => (string) $data['user_id']];
        }

        $search = $this->search($data);
        if ($search !== []) {
            $doc['search'] = $search;
        }

        // Kliknięcie: stały klucz zamiast id koperty — odświeżenie strony dostaje 409 (spec sekcja 4).
        $id = isset($data['dedup_key']) ? (string) $data['dedup_key'] : $event->id;

        match ($event->type) {
            'user.clicked_result' => $doc += $this->click($data),
            'user.added_to_cart' => $doc += $this->cart($data),
            'user.checked_out' => $doc += $this->checkout($data),
            default => null,
        };

        $items = [['index' => self::EVENTS_STREAM, 'id' => $id, 'doc' => $doc]];

        if ($event->type === 'user.searched') {
            array_push($items, ...$this->impressions($event, $timestamp, $search));
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function search(array $data): array
    {
        $search = [];
        foreach (['search_id' => 'id', 'query' => 'query', 'query_raw' => 'query_raw', 'sort' => 'sort'] as $from => $to) {
            if (isset($data[$from]) && $data[$from] !== '') {
                $search[$to] = (string) $data[$from];
            }
        }
        if (isset($data['total_hits'])) {
            $search['total_hits'] = (int) $data['total_hits'];
        }
        if (! empty($data['filters']) && \is_array($data['filters'])) {
            $search['filters'] = $data['filters'];
        }
        if (! empty($data['results']) && \is_array($data['results'])) {
            $search['results'] = array_map('strval', array_values($data['results']));
        }

        return $search;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function click(array $data): array
    {
        $out = [];
        if (isset($data['product_id'])) {
            $out['product'] = ['id' => (string) $data['product_id']];
        }
        if (isset($data['position'])) {
            $out['click'] = ['position' => (int) $data['position']];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function cart(array $data): array
    {
        $out = [];
        if (isset($data['product_id'])) {
            $out['product'] = ['id' => (string) $data['product_id']];
        }
        if (isset($data['offer_id'])) {
            $out['offer'] = ['id' => (string) $data['offer_id']];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function checkout(array $data): array
    {
        $out = [];
        if (! empty($data['product_ids']) && \is_array($data['product_ids'])) {
            $out['product'] = ['id' => array_map('strval', array_values($data['product_ids']))];
        }
        $order = [];
        if (! empty($data['order_ids']) && \is_array($data['order_ids'])) {
            $order['ids'] = array_map('strval', array_values($data['order_ids']));
        }
        if (isset($data['amount_cents'])) {
            $order['amount_cents'] = (int) $data['amount_cents'];
        }
        if ($order !== []) {
            $out['order'] = $order;
        }

        return $out;
    }

    /**
     * Jeden impression na produkt z listy wyników; pozycja 1-based, `_id` = `<search_id>:<pozycja>`
     * (powtórka paczki daje 409, nie drugi impression).
     *
     * @param  array<string, mixed>  $search
     * @return list<array{index: string, id: string, doc: array<string, mixed>}>
     */
    private function impressions(IntegrationEvent $event, string $timestamp, array $search): array
    {
        $searchId = $search['id'] ?? null;
        if ($searchId === null || empty($search['results'])) {
            return [];
        }

        $searchRef = array_intersect_key($search, ['id' => 1, 'query' => 1]);
        $items = [];
        foreach ($search['results'] as $i => $productId) {
            $position = $i + 1;
            $items[] = [
                'index' => self::IMPRESSIONS_STREAM,
                'id' => "{$searchId}:{$position}",
                'doc' => [
                    '@timestamp' => $timestamp,
                    'search' => $searchRef,
                    'session' => ['id' => $event->aggregateId],
                    'product' => ['id' => $productId],
                    'impression' => ['position' => $position],
                ],
            ];
        }

        return $items;
    }
}
