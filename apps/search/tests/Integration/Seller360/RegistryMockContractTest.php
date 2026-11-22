<?php

namespace App\Tests\Integration\Seller360;

use App\Seller360\Registry\RegistryClient;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Kontrakt mocka `tools/registry-mock` (ETAP 13) na ŻYWYM kontenerze `registry-mock`.
 * Pomijany, gdy mock nie odpowiada. Stan chaosu jest czyszczony przed i po teście.
 */
#[Group('integration')]
final class RegistryMockContractTest extends TestCase
{
    private const NIP = '5260250274';

    private string $base;

    protected function setUp(): void
    {
        $this->base = rtrim((string) ($_SERVER['REGISTRY_BASE_URL'] ?? getenv('REGISTRY_BASE_URL') ?: 'http://registry-mock:8080'), '/');
        try {
            $this->chaos(['reset' => true]);
        } catch (\Throwable $e) {
            self::markTestSkipped("Mock rejestru nieosiągalny ({$this->base}): {$e->getMessage()}");
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->chaos(['reset' => true]);
        } catch (\Throwable) {
        }
    }

    /** @return array<string, mixed> */
    private function chaos(array $body): array
    {
        return HttpClient::create()->request('POST', $this->base.'/_chaos', ['json' => $body, 'timeout' => 2])->toArray();
    }

    /** @return array<mixed> */
    private function get(string $path): array
    {
        return HttpClient::create()->request('GET', $this->base.$path, ['timeout' => 5])->toArray();
    }

    public function test_firma_i_kurier_sa_deterministyczne_z_nip(): void
    {
        $company = $this->get('/companies/'.self::NIP);

        self::assertSame(self::NIP, $company['nip']);
        self::assertContains($company['vat_status'], ['active', 'inactive']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $company['registered_at']);
        self::assertSame($company, $this->get('/companies/'.self::NIP));

        $courier = $this->get('/couriers/assignments/'.self::NIP)['courier_id'];
        self::assertMatchesRegularExpression('/^courier-[1-8]$/', $courier);
        self::assertSame($courier, $this->get('/couriers/assignments/'.self::NIP)['courier_id']);
    }

    public function test_oceny_wszystkich_8_kurierow_a_since_zwraca_tylko_zmienione(): void
    {
        $all = $this->get('/couriers/ratings');
        self::assertCount(8, $all);
        foreach ($all as $r) {
            self::assertGreaterThanOrEqual(0, $r['on_time_rate']);
            self::assertLessThanOrEqual(1, $r['on_time_rate']);
        }

        $before = gmdate('Y-m-d\TH:i:s\Z', time() - 1);
        self::assertSame([], $this->get('/couriers/ratings?since='.$before));

        $this->chaos(['bump_courier' => 'courier-3']);
        $changed = $this->get('/couriers/ratings?since='.$before);
        self::assertCount(1, $changed);
        self::assertSame('courier-3', $changed[0]['courier_id']);
        $old = array_values(array_filter($all, static fn (array $r): bool => $r['courier_id'] === 'courier-3'))[0];
        self::assertNotSame($old['on_time_rate'], $changed[0]['on_time_rate']);
    }

    public function test_outage_daje_503_po_timeoucie_a_klient_rzuca_registry_unavailable(): void
    {
        $this->chaos(['outage' => true]);

        $client = new RegistryClient($this->base, null, static function (): void {});
        $started = microtime(true);
        try {
            $client->company(self::NIP);
            self::fail('Oczekiwano RegistryUnavailable');
        } catch (\App\Seller360\Registry\RegistryUnavailable) {
            self::assertGreaterThanOrEqual(5.5, microtime(true) - $started, '3 próby po 2 s timeoutu');
        }

        $this->chaos(['outage' => false]);
        self::assertSame(self::NIP, $client->company(self::NIP)['nip']);
    }

    public function test_error_rate_1_zawsze_503_a_delay_opoznia(): void
    {
        $this->chaos(['error_rate' => 1]);
        $status = HttpClient::create()->request('GET', $this->base.'/companies/'.self::NIP)->getStatusCode(false);
        self::assertSame(503, $status);

        $this->chaos(['error_rate' => 0, 'delay_ms' => 300]);
        $started = microtime(true);
        $this->get('/companies/'.self::NIP);
        self::assertGreaterThanOrEqual(0.29, microtime(true) - $started);
    }
}
