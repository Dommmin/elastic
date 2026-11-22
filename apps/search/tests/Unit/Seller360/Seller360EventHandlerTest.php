<?php

namespace App\Tests\Unit\Seller360;

use App\Message\IntegrationEvent;
use App\MessageHandler\Seller360EventHandler;
use App\Seller360\RegionResolver;
use App\Seller360\SectionMeta;
use App\Seller360\SectionWriter;
use App\Seller360\Seller360Metrics;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class Seller360EventHandlerTest extends TestCase
{
    private const CSV = '/infra/reference/miasta-wojewodztwa.csv';

    private function handler(SectionWriter $writer, \Redis $redis): Seller360EventHandler
    {
        if (! is_file(self::CSV)) {
            self::markTestSkipped('Brak '.self::CSV.' (montowany w kontenerach search).');
        }

        return new Seller360EventHandler($writer, new RegionResolver(self::CSV), new Seller360Metrics($redis, new NullLogger), $redis, new NullLogger);
    }

    /** @param array<string, mixed> $data */
    private function event(string $type, string $aggregate, array $data): IntegrationEvent
    {
        return new IntegrationEvent('01J', $type, 1, 'catalog', new \DateTimeImmutable('2026-11-21T10:00:00+00:00'), $aggregate, '7', 1, $data);
    }

    public function test_zdarzenie_offer_dodaje_sprzedawce_do_zbioru_brudnych_i_nie_pisze(): void
    {
        $writer = $this->createMock(SectionWriter::class);
        $writer->expects(self::never())->method('apply');
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())->method('sAdd')->with('seller360:dirty:offers', '7');
        $redis->expects(self::once())->method('incr')->with('seller360_offer_events_total');

        $this->handler($writer, $redis)($this->event('offer.stock_changed', 'offer', ['seller_id' => 7, 'offer_id' => 99]));
    }

    public function test_seller_zapisuje_profile_z_polami_dokumentu_i_regionem(): void
    {
        $writer = $this->createMock(SectionWriter::class);
        $writer->expects(self::once())->method('apply')->with(
            7,
            'profile',
            ['name' => 'Nowa', 'city' => 'Kraków', 'status' => 'active', 'status_reason' => null],
            self::callback(fn (SectionMeta $m) => $m->version === 4 && $m->sourceTs->format('c') === '2026-11-21T09:59:00+00:00' && $m->status === 'ok'),
            ['nip' => '1234563218', 'region' => 'małopolskie', 'synthetic' => false],
        )->willReturn('updated');

        $this->handler($writer, $this->createStub(\Redis::class))($this->event('seller.renamed', 'seller', [
            'seller_id' => 7, 'name' => 'Nowa', 'city' => 'Kraków', 'nip' => '1234563218', 'status' => 'active',
            'status_reason' => null, 'synthetic' => false, 'version' => 4, 'occurred_at' => '2026-11-21T09:59:00+00:00',
        ]));
    }

    public function test_nieznane_miasto_daje_region_null_i_licznik(): void
    {
        $writer = $this->createMock(SectionWriter::class);
        $writer->expects(self::once())->method('apply')->with(
            7, 'profile', self::anything(), self::anything(),
            self::callback(fn (array $doc) => $doc['region'] === null),
        )->willReturn('created');
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())->method('incr')->with('seller360_region_unknown_total');

        $this->handler($writer, $redis)($this->event('seller.registered', 'seller', [
            'seller_id' => 7, 'name' => 'X', 'city' => 'Wąchock', 'nip' => '1', 'status' => 'pending',
            'status_reason' => null, 'synthetic' => true, 'version' => 1, 'occurred_at' => '2026-11-21T09:59:00+00:00',
        ]));
    }

    public function test_billing_zapisuje_sekcje_ze_snapshotu_z_last_invoice_id(): void
    {
        $writer = $this->createMock(SectionWriter::class);
        $writer->expects(self::once())->method('apply')->with(
            7,
            'billing',
            ['balance_cents' => 1200, 'overdue_cents' => 500, 'overdue_since' => '2026-11-01', 'open_invoices' => 2, 'last_invoice_id' => 'inv-9'],
            self::callback(fn (SectionMeta $m) => $m->version === 8),
        )->willReturn('created');

        $this->handler($writer, $this->createStub(\Redis::class))($this->event('billing.invoice_overdue', 'billing_account', [
            'seller_id' => 7, 'balance_cents' => 1200, 'overdue_cents' => 500, 'overdue_since' => '2026-11-01',
            'open_invoices' => 2, 'invoice_id' => 'inv-9', 'version' => 8, 'occurred_at' => '2026-11-21T09:59:00+00:00',
        ]));
    }

    public function test_nieznany_typ_to_ack_bez_zapisu(): void
    {
        $writer = $this->createMock(SectionWriter::class);
        $writer->expects(self::never())->method('apply');
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::never())->method('sAdd');

        $this->handler($writer, $redis)($this->event('review.created', 'review', ['seller_id' => 7]));
    }

    public function test_ladunek_bez_seller_id_jest_nieodwracalnie_zly(): void
    {
        $this->expectException(UnrecoverableMessageHandlingException::class);

        $this->handler($this->createStub(SectionWriter::class), $this->createStub(\Redis::class))(
            $this->event('billing.payment_received', 'billing_account', ['version' => 1]),
        );
    }
}
