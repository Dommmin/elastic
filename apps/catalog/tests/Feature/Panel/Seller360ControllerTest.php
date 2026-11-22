<?php

use App\Models\User;
use App\Seller360\Seller360Query;
use App\Seller360\Seller360Reader;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Transport\Exception\NoNodeAvailableException;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Fałszywy reader zamiast ES: dokumenty po `_id` i zapamiętane ciało ostatniego `_search`.
 *
 * @param  array<int, array<string, mixed>>  $docs
 */
function fakeSeller360Reader(array $docs = [], bool $down = false, ?Throwable $searchError = null): object
{
    $fake = new class($docs, $down, $searchError) extends Seller360Reader
    {
        public ?array $lastBody = null;

        public function __construct(private array $docs, private bool $down, private ?Throwable $searchError) {}

        public function search(array $body): array
        {
            if ($this->down) {
                throw new NoNodeAvailableException('ES leży');
            }
            if ($this->searchError !== null) {
                throw $this->searchError;
            }
            $this->lastBody = $body;
            $hits = [];
            foreach ($this->docs as $id => $source) {
                $hits[] = ['id' => (string) $id, 'source' => $source];
            }

            return ['took' => 3, 'total' => count($hits), 'hits' => $hits];
        }

        public function find(int $sellerId): ?array
        {
            if ($this->down) {
                throw new NoNodeAvailableException('ES leży');
            }

            return $this->docs[$sellerId] ?? null;
        }
    };
    app()->instance(Seller360Reader::class, $fake);

    return $fake;
}

function profileOnlyDoc(): array
{
    return [
        'seller_id' => '42',
        'nip' => '5260250274',
        'region' => 'mazowieckie',
        'synthetic' => false,
        'profile' => [
            'name' => 'Hurtownia Testowa',
            'city' => 'Warszawa',
            'status' => 'active',
            'status_reason' => null,
            '_meta' => [
                'version' => 3,
                'source_ts' => now()->subMinutes(5)->toIso8601ZuluString(),
                'updated_at' => now()->subMinutes(3)->toIso8601ZuluString(),
                'status' => 'ok',
                'error' => null,
            ],
        ],
    ];
}

test('gość jest przekierowany na login', function () {
    fakeSeller360Reader();

    $this->get('/panel/sellers-360')->assertRedirect('/login');
});

test('403 dla użytkownika bez is_admin', function () {
    fakeSeller360Reader([42 => profileOnlyDoc()]);
    $user = User::factory()->create();

    $this->actingAs($user)->get('/panel/sellers-360')->assertForbidden();
    $this->actingAs($user)->get('/panel/sellers-360/42')->assertForbidden();
});

test('renders seller with only profile section', function () {
    fakeSeller360Reader([42 => profileOnlyDoc()]);

    $this->actingAs(User::factory()->admin()->create())
        ->get('/panel/sellers-360/42')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('panel/sellers360/Show')
            ->where('seller.id', 42)
            ->where('seller.nip', '5260250274')
            ->where('seller.name', 'Hurtownia Testowa')
            ->has('seller.sections', 6)
            ->where('seller.sections.0.key', 'profile')
            ->where('seller.sections.0.present', true)
            ->where('seller.sections.0.meta.status', 'ok')
            ->where('seller.sections.0.data.city', 'Warszawa')
            ->where('seller.sections.1.key', 'offers')
            ->where('seller.sections.1.present', false)
            ->where('seller.sections.1.missing_label', 'brak danych z oferty')
            ->where('seller.sections.2.missing_label', 'brak danych z analityka')
            ->where('seller.sections.3.missing_label', 'brak danych z billing')
            ->where('seller.sections.4.missing_label', 'brak danych z reklamacje')
            ->where('seller.sections.5.missing_label', 'brak danych z rejestr'));
});

test('nieznany sprzedawca to 404', function () {
    fakeSeller360Reader();

    $this->actingAs(User::factory()->admin()->create())
        ->get('/panel/sellers-360/999')
        ->assertNotFound();
});

test('lista przekazuje filtry do zapytania i zwraca wiersze także bez sekcji', function () {
    $fake = fakeSeller360Reader([42 => profileOnlyDoc()]);

    $this->actingAs(User::factory()->admin()->create())
        ->get('/panel/sellers-360?region=mazowieckie&overdue=1&sort=gmv')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('panel/sellers360/Index')
            ->where('es_available', true)
            ->where('total', 1)
            ->where('took', 3)
            ->where('sellers.0.id', 42)
            ->where('sellers.0.name', 'Hurtownia Testowa')
            ->where('sellers.0.gmv_30d_cents', null)
            ->where('sellers.0.overdue_cents', null)
            ->where('filters.region', 'mazowieckie')
            ->where('filters.overdue', true)
            ->where('filters.sort', 'gmv')
            ->where('dodPreset.complaint_phrase', 'uszkodzon*'));

    expect($fake->lastBody['query']['bool']['filter'])->toContain(['term' => ['region' => 'mazowieckie']])
        ->and($fake->lastBody['sort'][0])->toHaveKey('sales.gmv_30d_cents');
});

test('ES niedostępny: lista renderuje się z komunikatem', function () {
    fakeSeller360Reader(down: true);

    $this->actingAs(User::factory()->admin()->create())
        ->get('/panel/sellers-360')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('es_available', false)
            ->has('sellers', 0));
});

test('ES niedostępny: karta sprzedawcy renderuje się z komunikatem zamiast 500', function () {
    fakeSeller360Reader(down: true);

    $this->actingAs(User::factory()->admin()->create())
        ->get('/panel/sellers-360/42')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('panel/sellers360/Show')
            ->where('es_available', false)
            ->where('seller', null)
            ->where('sellerId', 42));
});

test('4xx z ES to błąd zapytania, a nie „ES niedostępny”', function () {
    fakeSeller360Reader(searchError: new ClientResponseException('400 Bad Request: search_phase_execution_exception', 400));

    $this->actingAs(User::factory()->admin()->create())
        ->get('/panel/sellers-360?q=x')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('es_available', true)
            ->where('query_error', 400)
            ->has('sellers', 0));
});

test('numer strony jest przycięty do okna from+size ≤ 10 000', function () {
    $fake = fakeSeller360Reader([42 => profileOnlyDoc()]);

    $this->actingAs(User::factory()->admin()->create())
        ->get('/panel/sellers-360?page=999999')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('page', 400));

    expect($fake->lastBody['from'] + $fake->lastBody['size'])->toBeLessThanOrEqual(10000)
        ->and($fake->lastBody['from'])->toBe(10000 - Seller360Query::DEFAULT_SIZE);
});
