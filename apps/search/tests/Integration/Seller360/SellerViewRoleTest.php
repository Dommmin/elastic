<?php

namespace App\Tests\Integration\Seller360;

use App\Service\ElasticsearchClientFactory;
use App\Tests\Support\RealElasticsearch;
use Elastic\Elasticsearch\Client;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Rola `seller_stats_view` na ŻYWYM Elasticsearchu (ETAP 13, Task 15). Licencja basic nie ma FLS/DLS,
 * więc izolacja to osobny alias `seller-360-public`: konto z rolą czyta go przez `_doc`, a `seller-360`
 * (pełny dokument z billing i complaints) dostaje 403.
 *
 * Konto tworzy sam test, z losowym hasłem, i kasuje je w tearDown. Zarządzać użytkownikami może tylko
 * superużytkownik, którego kontener search-consumer celowo NIE ma, więc test bierze ELASTIC_PASSWORD
 * z otoczenia i bez niego się pomija:
 *   docker compose exec -e ELASTIC_PASSWORD="$(grep '^ELASTIC_PASSWORD=' .env | cut -d= -f2-)" search-consumer \
 *     php bin/phpunit tests/Integration/Seller360/SellerViewRoleTest.php
 * Dokument testowy ma id spoza zakresu seedera, z `synthetic: true`, i znika w tearDown.
 */
#[Group('integration')]
final class SellerViewRoleTest extends TestCase
{
    use RealElasticsearch;

    private const ROLE = 'seller_stats_view';
    private const DOC_ID = '987654321';

    private Client $admin;
    private Client $seller;
    private string $user;

    protected function setUp(): void
    {
        $host = $this->env('ELASTICSEARCH_HOST');
        $superuserPassword = $this->env('ELASTIC_PASSWORD');
        if ($host === '' || $superuserPassword === '') {
            self::markTestSkipped('Brak ELASTICSEARCH_HOST lub ELASTIC_PASSWORD: zarządzanie użytkownikami wymaga superużytkownika (patrz opis klasy).');
        }

        $this->admin = (new ElasticsearchClientFactory($host, 'elastic', $superuserPassword))->create();
        try {
            $this->admin->security()->getRole(['name' => self::ROLE]);
        } catch (\Throwable $e) {
            self::markTestSkipped('Brak roli '.self::ROLE.' albo ES niedostępny (uruchom infra/elasticsearch/setup-security.sh): '.$e->getMessage());
        }

        $this->user = 'seller-view-test-'.bin2hex(random_bytes(4));
        $password = bin2hex(random_bytes(16));
        $this->admin->security()->putUser(['username' => $this->user, 'body' => ['password' => $password, 'roles' => [self::ROLE]]]);
        $this->seller = (new ElasticsearchClientFactory($host, $this->user, $password))->create();

        $meta = ['version' => 1, 'source_ts' => '2026-11-22T09:00:00Z', 'updated_at' => '2026-11-22T09:00:00Z', 'status' => 'ok'];
        $this->admin->index(['index' => 'seller-360-public', 'id' => self::DOC_ID, 'refresh' => 'true', 'body' => [
            'seller_id' => self::DOC_ID, 'synthetic' => true,
            'profile' => ['name' => 'Sklep testowy roli', 'city' => 'Łódź', 'status' => 'active', 'status_reason' => null, '_meta' => $meta],
            'offers' => ['active' => 1, 'total' => 1, '_meta' => $meta],
        ]]);
    }

    protected function tearDown(): void
    {
        if (! isset($this->admin, $this->user)) {
            return;
        }
        $this->admin->delete(['index' => 'seller-360-public', 'id' => self::DOC_ID, 'refresh' => 'true', 'client' => ['ignore' => [404]]]);
        $this->admin->security()->deleteUser(['username' => $this->user, 'client' => ['ignore' => [404]]]);
    }

    public function test_konto_z_rola_czyta_dokument_publiczny_przez_doc(): void
    {
        $doc = $this->seller->get(['index' => 'seller-360-public', 'id' => self::DOC_ID])->asArray();

        self::assertTrue($doc['found']);
        self::assertSame('Sklep testowy roli', $doc['_source']['profile']['name']);
    }

    public function test_dokument_publiczny_nie_ma_billing_ani_complaints(): void
    {
        $source = $this->seller->get(['index' => 'seller-360-public', 'id' => self::DOC_ID])->asArray()['_source'];

        self::assertArrayNotHasKey('billing', $source);
        self::assertArrayNotHasKey('complaints', $source);
    }

    public function test_search_po_pelnym_aliasie_seller_360_daje_403(): void
    {
        $this->expectExceptionCode(403);

        $this->seller->search(['index' => 'seller-360', 'body' => ['query' => ['match_all' => (object) []]]]);
    }

    public function test_odczyt_pelnego_dokumentu_seller_360_daje_403(): void
    {
        $this->expectExceptionCode(403);

        $this->seller->get(['index' => 'seller-360', 'id' => self::DOC_ID]);
    }

    public function test_konto_nie_pisze_do_aliasu_publicznego(): void
    {
        $this->expectExceptionCode(403);

        $this->seller->index(['index' => 'seller-360-public', 'id' => 'x', 'body' => ['seller_id' => 'x']]);
    }
}
