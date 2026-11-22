<?php

use App\Console\Commands\LabExportCommand;
use App\Models\Category;
use App\Models\Product;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * `search:lab:export` bez żywego ES: sztuczny `_mget` odpowiada dokumentami z `$docs`
 * (id => _source); brak wpisu = `found:false`.
 *
 * @param  array<string, array<string, mixed>>  $docs
 */
function fakeLabEs(array &$docs): ClientInterface
{
    return new class($docs) implements ClientInterface
    {
        public function __construct(private array &$docs) {}

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            $body = json_decode((string) $request->getBody(), true) ?? [];
            $ids = $body['ids'] ?? array_column($body['docs'] ?? [], '_id');
            $result = array_map(fn ($id) => isset($this->docs[$id])
                ? ['_id' => (string) $id, 'found' => true, '_source' => $this->docs[$id]]
                : ['_id' => (string) $id, 'found' => false], $ids);

            return new Response(200, ['X-Elastic-Product' => 'Elasticsearch', 'Content-Type' => 'application/json'], json_encode(['docs' => $result]));
        }
    };
}

/** @return list<array<string, mixed>> linie danych (bez nagłówka) */
function labExportLines(string $path = 'lab-test/products.ndjson'): array
{
    $lines = array_values(array_filter(explode("\n", Storage::disk('local')->get($path))));

    return array_map(fn ($l) => json_decode($l, true), $lines);
}

beforeEach(function () {
    $this->docs = [];
    $this->app->instance(Client::class, ClientBuilder::create()
        ->setHosts(['http://es.invalid:9200'])
        ->setHttpClient(fakeLabEs($this->docs))
        ->build());
    Storage::disk('local')->deleteDirectory('lab-test');
});

afterEach(fn () => Storage::disk('local')->deleteDirectory('lab-test'));

function labProduct(int $id, array $attrs = []): Product
{
    $test = test();
    $docs = $test->docs;
    $docs[(string) $id] = ['in_stock' => true, 'price_min' => 1999];
    $test->docs = $docs;

    return Product::factory()->create(['id' => $id] + $attrs);
}

test('exports only products with id divisible by modulo', function () {
    foreach ([58, 59, 116, 117, 174, 175] as $id) {
        labProduct($id);
    }

    $this->artisan('search:lab:export', ['--out' => 'lab-test/products.ndjson'])->assertSuccessful();

    $ids = array_column(array_slice(labExportLines(), 1), 'id');
    expect(array_map('intval', $ids))->toBe([58, 116, 174]);
});

test('builds lab_text from name and category names', function () {
    $root = Category::factory()->create(['name' => 'Odzież']);
    $leaf = Category::factory()->create(['name' => 'Kurtki', 'parent_id' => $root->id, 'path' => 'odziez/kurtki']);
    labProduct(58, ['name' => 'Kurtka zimowa Alpha', 'category_id' => $leaf->id]);

    $this->artisan('search:lab:export', ['--out' => 'lab-test/products.ndjson'])->assertSuccessful();

    $doc = labExportLines()[1];
    expect($doc['lab_text'])->toBe('Kurtka zimowa Alpha. Kurtki, Odzież')
        ->and($doc['category_leaf'])->toBe('Kurtki')
        ->and($doc['category_root'])->toBe('Odzież')
        ->and($doc['category'])->toBe(['id' => (string) $leaf->id, 'path' => 'odziez/kurtki'])
        ->and(array_keys($doc))->toBe(['id', 'name', 'description', 'brand', 'category', 'category_leaf', 'category_root', 'in_stock', 'price_min', 'lab_text']);
});

test('takes in_stock and price_min from the search index, not the database', function () {
    labProduct(58);
    $docs = $this->docs;
    $docs['58'] = ['in_stock' => false, 'price_min' => 4242];
    $this->docs = $docs;

    $this->artisan('search:lab:export', ['--out' => 'lab-test/products.ndjson'])->assertSuccessful();

    $doc = labExportLines()[1];
    expect($doc['in_stock'])->toBeFalse()->and($doc['price_min'])->toBe(4242);
});

test('fails loudly when a sampled product is missing from the index', function () {
    labProduct(58);
    $docs = $this->docs;
    unset($docs['58']);
    $this->docs = $docs;

    $this->artisan('search:lab:export', ['--out' => 'lab-test/products.ndjson'])
        ->expectsOutputToContain('58')
        ->assertExitCode(1);
});

test('writes the header line with modulo and count', function () {
    labProduct(58);
    labProduct(116);

    $this->artisan('search:lab:export', ['--out' => 'lab-test/products.ndjson'])->assertSuccessful();

    $header = labExportLines()[0]['_lab_export'];
    expect($header['modulo'])->toBe(LabExportCommand::MODULO)->toBe(58)
        ->and($header['count'])->toBe(2)
        ->and($header['exported_at'])->toBeString();
});
