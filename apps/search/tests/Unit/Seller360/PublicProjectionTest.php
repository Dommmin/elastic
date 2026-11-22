<?php

namespace App\Tests\Unit\Seller360;

use App\Seller360\PublicProjection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublicProjectionTest extends TestCase
{
    public function test_billing_i_complaints_nigdy_nie_trafiaja_do_publicznego_indeksu(): void
    {
        self::assertNull(PublicProjection::filter('billing', ['balance_cents' => 1]));
        self::assertNull(PublicProjection::filter('complaints', ['open' => 3]));
    }

    public function test_nieznana_sekcja_to_null_a_nie_wyciek(): void
    {
        self::assertNull(PublicProjection::filter('cokolwiek', ['x' => 1]));
    }

    /** @param array<string, mixed> $data */
    #[DataProvider('passthrough')]
    public function test_profile_offers_i_sales_przechodza_bez_zmian(string $section, array $data): void
    {
        self::assertSame($data, PublicProjection::filter($section, $data));
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function passthrough(): iterable
    {
        yield 'profile' => ['profile', ['name' => 'Sklep', 'city' => 'Łódź']];
        yield 'offers' => ['offers', ['active' => 3, 'total' => 5]];
        yield 'sales' => ['sales', ['gmv_30d_cents' => 100, 'orders_30d' => 2]];
    }

    public function test_registry_nie_zawiera_vat_status_legal_name_ani_registered_at(): void
    {
        $filtered = PublicProjection::filter('registry', [
            'legal_name' => 'Sklep Sp. z o.o.',
            'vat_status' => 'Czynny',
            'registered_at' => '2020-01-01',
            'courier' => ['id' => 'k3', 'on_time_rate' => 0.93, 'rated_at' => '2026-11-21T10:00:00Z'],
        ]);

        self::assertSame(['courier' => ['id' => 'k3', 'on_time_rate' => 0.93, 'rated_at' => '2026-11-21T10:00:00Z']], $filtered);
    }

    public function test_registry_bez_kuriera_to_pusta_tablica(): void
    {
        self::assertSame([], PublicProjection::filter('registry', ['vat_status' => 'Czynny']));
    }

    /** Ruling R8: powód blokady to wewnętrzna notatka moderacji, nie dla kupującego. */
    public function test_profile_bez_status_reason(): void
    {
        self::assertSame(
            ['name' => 'Sklep', 'city' => 'Łódź', 'status' => 'blocked'],
            PublicProjection::filter('profile', ['name' => 'Sklep', 'city' => 'Łódź', 'status' => 'blocked', 'status_reason' => 'Oszustwo w zgłoszeniu']),
        );
    }

    /** Pola dokumentu: biała lista `region` i `synthetic` (purge kasuje kopię publiczną po `synthetic:true`), bez `nip`. */
    public function test_pola_dokumentu_bez_nip(): void
    {
        self::assertSame(
            ['region' => 'łódzkie', 'synthetic' => true],
            PublicProjection::filterDoc(['nip' => '1234563218', 'region' => 'łódzkie', 'synthetic' => true, 'cokolwiek' => 1]),
        );
        self::assertSame([], PublicProjection::filterDoc(['nip' => '1234563218']));
    }
}
