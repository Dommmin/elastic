<?php

namespace App\Tests\Unit\Analytics;

use App\Analytics\AnalyticsDocumentMapper;
use App\Message\IntegrationEvent;
use PHPUnit\Framework\TestCase;

final class AnalyticsDocumentMapperTest extends TestCase
{
    public function test_wyszukanie_daje_dokument_zdarzenia_i_impression_na_kazdy_wynik(): void
    {
        $docs = (new AnalyticsDocumentMapper())->map($this->event('user.searched', [
            'search_id' => 's-1',
            'query' => 'buty',
            'query_raw' => ' Buty ',
            'filters' => ['category' => 'obuwie'],
            'total_hits' => 42,
            'results' => ['10', '20', '30'],
            'sort' => 'relevance',
        ]));

        $this->assertCount(4, $docs);

        $event = $docs[0];
        $this->assertSame('events-user-default', $event['index']);
        $this->assertSame('evt-1', $event['id']);
        $this->assertSame('2026-11-20T10:00:00.000+00:00', $event['doc']['@timestamp']);
        $this->assertSame(['id' => 'evt-1', 'type' => 'user.searched'], $event['doc']['event']);
        $this->assertSame(['id' => 'sess-1'], $event['doc']['session']);
        $this->assertSame('s-1', $event['doc']['search']['id']);
        $this->assertSame('buty', $event['doc']['search']['query']);
        $this->assertSame(42, $event['doc']['search']['total_hits']);
        $this->assertSame(['10', '20', '30'], $event['doc']['search']['results']);

        foreach ([1, 2, 3] as $i => $position) {
            $impression = $docs[$i + 1];
            $this->assertSame('events-impression-default', $impression['index']);
            $this->assertSame("s-1:{$position}", $impression['id']);
            $this->assertSame($position, $impression['doc']['impression']['position']);
            $this->assertSame((string) (($i + 1) * 10), $impression['doc']['product']['id']);
            $this->assertSame('s-1', $impression['doc']['search']['id']);
            $this->assertSame('buty', $impression['doc']['search']['query']);
            $this->assertSame(['id' => 'sess-1'], $impression['doc']['session']);
            $this->assertSame($event['doc']['@timestamp'], $impression['doc']['@timestamp']);
        }
    }

    public function test_klikniecie_z_dedup_key_uzywa_go_jako_id(): void
    {
        $docs = (new AnalyticsDocumentMapper())->map($this->event('user.clicked_result', [
            'search_id' => 's-1', 'query' => 'buty', 'product_id' => 10, 'position' => 2,
            'dedup_key' => 'click:s-1:10',
        ]));

        $this->assertCount(1, $docs);
        $this->assertSame('events-user-default', $docs[0]['index']);
        $this->assertSame('click:s-1:10', $docs[0]['id']);
        $this->assertSame('10', $docs[0]['doc']['product']['id']);
        $this->assertSame(2, $docs[0]['doc']['click']['position']);
        $this->assertSame('evt-1', $docs[0]['doc']['event']['id']);
    }

    public function test_bez_dedup_key_id_to_id_koperty(): void
    {
        $docs = (new AnalyticsDocumentMapper())->map($this->event('user.clicked_result', ['product_id' => 10, 'position' => 1]));

        $this->assertSame('evt-1', $docs[0]['id']);
    }

    public function test_klikniecie_z_offer_id_ma_offer_id_dla_sekwencji_eql(): void
    {
        // ETAP 15: `price-bait-and-switch` łączy kliknięcia i zmiany cen po `offer.id`.
        $docs = (new AnalyticsDocumentMapper())->map($this->event('user.clicked_result', ['product_id' => 10, 'offer_id' => 77, 'position' => 1]));

        $this->assertSame('77', $docs[0]['doc']['offer']['id']);
        $this->assertArrayNotHasKey('offer', (new AnalyticsDocumentMapper())->map($this->event('user.clicked_result', ['product_id' => 10]))[0]['doc']);
    }

    public function test_dodanie_do_koszyka_i_user_id(): void
    {
        $docs = (new AnalyticsDocumentMapper())->map($this->event('user.added_to_cart', [
            'product_id' => 10, 'offer_id' => 7, 'search_id' => 's-1', 'query' => 'buty', 'user_id' => 5,
        ]));

        $this->assertSame('10', $docs[0]['doc']['product']['id']);
        $this->assertSame('7', $docs[0]['doc']['offer']['id']);
        $this->assertSame('5', $docs[0]['doc']['user']['id']);
        $this->assertSame('s-1', $docs[0]['doc']['search']['id']);
    }

    public function test_checkout(): void
    {
        $docs = (new AnalyticsDocumentMapper())->map($this->event('user.checked_out', [
            'order_ids' => [1, 2], 'product_ids' => [10, 11], 'amount_cents' => 12345,
        ]));

        $this->assertSame(['1', '2'], $docs[0]['doc']['order']['ids']);
        $this->assertSame(12345, $docs[0]['doc']['order']['amount_cents']);
        $this->assertSame(['10', '11'], $docs[0]['doc']['product']['id']);
        $this->assertArrayNotHasKey('search', $docs[0]['doc']);
    }

    public function test_nieznany_typ_daje_pusta_liste(): void
    {
        $this->assertSame([], (new AnalyticsDocumentMapper())->map($this->event('product.created', [])));
    }

    public function test_puste_pola_opcjonalne_nie_trafiaja_do_dokumentu(): void
    {
        $docs = (new AnalyticsDocumentMapper())->map($this->event('user.searched', [
            'search_id' => 's-1', 'query' => 'x', 'filters' => [], 'results' => [], 'total_hits' => 0,
        ]));

        $this->assertCount(1, $docs);
        $this->assertArrayNotHasKey('filters', $docs[0]['doc']['search']);
        $this->assertArrayNotHasKey('results', $docs[0]['doc']['search']);
        $this->assertArrayNotHasKey('sort', $docs[0]['doc']['search']);
    }

    public function test_checkout_v2_daje_dokument_zdarzenia_bez_zmian_i_wiersz_na_sprzedawce(): void
    {
        $data = ['order_ids' => [1, 2], 'product_ids' => [10, 11], 'amount_cents' => 2000];
        $v1 = (new AnalyticsDocumentMapper())->map($this->event('user.checked_out', $data));
        $docs = (new AnalyticsDocumentMapper())->map($this->event('user.checked_out', $data + ['seller_amounts' => ['7' => 1500, '9' => 500]], version: 2));

        $this->assertCount(3, $docs);
        $this->assertSame($v1[0], $docs[0], 'dokument events-user z v2 jest identyczny jak z v1');

        $this->assertSame('events-seller-default', $docs[1]['index']);
        $this->assertSame('evt-1:7', $docs[1]['id']);
        $this->assertSame([
            '@timestamp' => '2026-11-20T10:00:00.000+00:00',
            'event' => ['id' => 'evt-1', 'type' => 'user.checked_out'],
            'session' => ['id' => 'sess-1'],
            'seller' => ['id' => '7'],
            'kind' => 'order',
            'amount_cents' => 1500,
        ], $docs[1]['doc']);
        $this->assertSame('evt-1:9', $docs[2]['id']);
        $this->assertSame(500, $docs[2]['doc']['amount_cents']);
    }

    public function test_checkout_v1_bez_seller_amounts_nie_daje_wierszy_events_seller(): void
    {
        $docs = (new AnalyticsDocumentMapper())->map($this->event('user.checked_out', ['order_ids' => [1], 'amount_cents' => 100]));

        $this->assertCount(1, $docs);
        $this->assertSame('events-user-default', $docs[0]['index']);
    }

    public function test_seller_amounts_w_v1_jest_ignorowane(): void
    {
        $docs = (new AnalyticsDocumentMapper())->map($this->event('user.checked_out', ['seller_amounts' => ['7' => 100]]));

        $this->assertCount(1, $docs);
    }

    public function test_dodanie_do_koszyka_v2_daje_seller_id_i_wiersz_kind_cart(): void
    {
        $docs = (new AnalyticsDocumentMapper())->map($this->event('user.added_to_cart', [
            'product_id' => 10, 'offer_id' => 7, 'seller_id' => 42, 'user_id' => 5,
        ], version: 2));

        $this->assertCount(2, $docs);
        $this->assertSame('events-user-default', $docs[0]['index']);
        $this->assertSame(['id' => '42'], $docs[0]['doc']['seller']);

        $this->assertSame('events-seller-default', $docs[1]['index']);
        $this->assertSame('evt-1:42', $docs[1]['id']);
        $this->assertSame('cart', $docs[1]['doc']['kind']);
        $this->assertSame(['id' => '42'], $docs[1]['doc']['seller']);
        $this->assertSame('sess-1', $docs[1]['doc']['session']['id']);
        $this->assertArrayNotHasKey('amount_cents', $docs[1]['doc']);
    }

    public function test_dodanie_do_koszyka_v1_nie_ma_seller_ani_wiersza(): void
    {
        $docs = (new AnalyticsDocumentMapper())->map($this->event('user.added_to_cart', ['product_id' => 10, 'offer_id' => 7, 'seller_id' => 42]));

        $this->assertCount(1, $docs);
        $this->assertArrayNotHasKey('seller', $docs[0]['doc']);
    }

    /** @param array<string, mixed> $data */
    private function event(string $type, array $data, int $version = 1): IntegrationEvent
    {
        return new IntegrationEvent(
            id: 'evt-1',
            type: $type,
            version: $version,
            source: 'catalog',
            occurredAt: new \DateTimeImmutable('2026-11-20T10:00:00+00:00'),
            aggregateType: 'session',
            aggregateId: 'sess-1',
            sequence: 0,
            data: $data,
        );
    }
}
