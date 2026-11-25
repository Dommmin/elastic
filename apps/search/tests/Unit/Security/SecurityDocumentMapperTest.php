<?php

namespace App\Tests\Unit\Security;

use App\Message\IntegrationEvent;
use App\Security\SecurityDocumentMapper;
use PHPUnit\Framework\TestCase;

final class SecurityDocumentMapperTest extends TestCase
{
    /** @param array<string, mixed> $data */
    private function event(string $type, string $aggregateType, string $aggregateId, array $data): IntegrationEvent
    {
        return new IntegrationEvent('01JEVENT', $type, 1, 'catalog', new \DateTimeImmutable('2026-11-25T10:00:00+02:00'), $aggregateType, $aggregateId, 3, $data);
    }

    /** @param array<string, mixed> $extra */
    private function price(int $old, int $new, array $extra = []): IntegrationEvent
    {
        return $this->event('offer.price_changed', 'offer', '42', ['id' => 42, 'product_id' => 7, 'seller_id' => 5, 'old_price_cents' => $old, 'new_price_cents' => $new, ...$extra]);
    }

    public function test_zmiana_ceny_liczy_change_pct_ze_znakiem_i_stringuje_identyfikatory(): void
    {
        $items = (new SecurityDocumentMapper())->map($this->price(10000, 12750));

        self::assertCount(1, $items);
        self::assertSame(SecurityDocumentMapper::STREAM, $items[0]['index']);
        self::assertSame('events-security-default', $items[0]['index']);
        self::assertSame('01JEVENT', $items[0]['id']);
        $doc = $items[0]['doc'];
        self::assertSame(27.5, $doc['offer']['change_pct']);
        self::assertSame('42', $doc['offer']['id']);
        self::assertSame(10000, $doc['offer']['old_price_cents']);
        self::assertSame(12750, $doc['offer']['new_price_cents']);
        self::assertSame('7', $doc['product']['id']);
        self::assertSame('5', $doc['seller']['id']);
        self::assertSame(['id' => '01JEVENT', 'type' => 'offer.price_changed'], $doc['event']);
        self::assertSame('2026-11-25T08:00:00.000+00:00', $doc['@timestamp']);
        self::assertArrayNotHasKey('labels', $doc);
    }

    public function test_obnizka_ma_ujemne_change_pct_zaokraglone_do_dwoch_miejsc(): void
    {
        $doc = (new SecurityDocumentMapper())->map($this->price(30000, 25001))[0]['doc'];

        self::assertSame(-16.66, $doc['offer']['change_pct']);
    }

    public function test_stara_cena_zero_pomija_change_pct_ale_zapisuje_zdarzenie(): void
    {
        $items = (new SecurityDocumentMapper())->map($this->price(0, 5000));

        self::assertCount(1, $items);
        self::assertArrayNotHasKey('change_pct', $items[0]['doc']['offer']);
        self::assertSame(5000, $items[0]['doc']['offer']['new_price_cents']);
    }

    public function test_synthetic_true_daje_label_a_inna_wartosc_nie(): void
    {
        $mapper = new SecurityDocumentMapper();

        self::assertSame(['synthetic' => true], $mapper->map($this->price(100, 110, ['synthetic' => true]))[0]['doc']['labels']);
        self::assertArrayNotHasKey('labels', $mapper->map($this->price(100, 110, ['synthetic' => false]))[0]['doc']);
        self::assertArrayNotHasKey('labels', $mapper->map($this->price(100, 110, ['synthetic' => 'true']))[0]['doc']);
    }

    public function test_recenzja_z_odciskiem_klienta(): void
    {
        $event = $this->event('review.created', 'review', '99', [
            'id' => 99, 'product_id' => 7, 'user_id' => 920000001, 'rating' => 5, 'body' => 'Super',
            'client_ip_hash' => 'a1b2c3d4e5f60718', 'client_ip_prefix' => '203.0.113.0/24',
            'client_user_agent' => 'Mozilla/5.0', 'user_account_age_hours' => 3, 'synthetic' => true,
        ]);

        $doc = (new SecurityDocumentMapper())->map($event)[0]['doc'];

        self::assertSame('99', $doc['review']['id']);
        self::assertSame(5, $doc['review']['rating']);
        self::assertSame('7', $doc['product']['id']);
        self::assertSame('920000001', $doc['user']['id']);
        self::assertSame(3, $doc['user']['account_age_hours']);
        self::assertSame(['ip_hash' => 'a1b2c3d4e5f60718', 'ip_prefix' => '203.0.113.0/24'], $doc['client']);
        self::assertSame(['original' => 'Mozilla/5.0'], $doc['user_agent']);
        self::assertSame(['synthetic' => true], $doc['labels']);
        self::assertArrayNotHasKey('offer', $doc);
        self::assertArrayNotHasKey('body', $doc['review']);
    }

    public function test_recenzja_bez_pol_klienta_nie_ma_kluczy_client_ani_user_agent(): void
    {
        $event = $this->event('review.created', 'review', '99', ['id' => 99, 'product_id' => 7, 'user_id' => 12, 'rating' => 4]);

        $doc = (new SecurityDocumentMapper())->map($event)[0]['doc'];

        self::assertArrayNotHasKey('client', $doc);
        self::assertArrayNotHasKey('user_agent', $doc);
        self::assertArrayNotHasKey('account_age_hours', $doc['user']);
    }

    public function test_recenzja_z_ip_null_nie_tworzy_klucza_client(): void
    {
        $event = $this->event('review.created', 'review', '99', ['id' => 99, 'product_id' => 7, 'user_id' => 12, 'rating' => 4, 'client_ip_hash' => null, 'client_ip_prefix' => null]);

        self::assertArrayNotHasKey('client', (new SecurityDocumentMapper())->map($event)[0]['doc']);
    }

    public function test_inny_typ_to_pusta_lista(): void
    {
        self::assertSame([], (new SecurityDocumentMapper())->map($this->event('offer.published', 'offer', '42', ['product_id' => 7])));
    }

    public function test_zniekształcony_payload_rzuca_wyjatek(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SecurityDocumentMapper())->map($this->event('offer.price_changed', 'offer', '42', ['product_id' => [1], 'seller_id' => 5, 'old_price_cents' => 1, 'new_price_cents' => 2]));
    }
}
