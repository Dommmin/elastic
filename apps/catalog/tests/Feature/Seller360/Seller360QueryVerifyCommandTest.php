<?php

use App\Seller360\Seller360Query;
use App\Seller360\Seller360Reader;
use Illuminate\Support\Facades\File;

/**
 * @param  list<int>  $ids  identyfikatory, które „zwraca ES” na zapytanie z DoD
 */
function fakeVerifyReader(array $ids, int $took = 7): object
{
    $fake = new class($ids, $took) extends Seller360Reader
    {
        public int $calls = 0;

        public ?array $lastBody = null;

        public function __construct(private array $ids, private int $took) {}

        public function search(array $body): array
        {
            $this->calls++;
            $this->lastBody = $body;

            return [
                'took' => $this->took,
                'total' => count($this->ids),
                'hits' => array_map(fn (int $id): array => ['id' => (string) $id, 'source' => []], $this->ids),
            ];
        }
    };
    app()->instance(Seller360Reader::class, $fake);

    return $fake;
}

beforeEach(function () {
    $this->dir = storage_path('framework/testing/seller360-verify-'.uniqid());
    File::ensureDirectoryExists($this->dir);
    config(['seller360.simulation_dir' => $this->dir]);
    File::put($this->dir.'/planted.json', json_encode([
        'planted' => [11, 12, 13],
        'near_misses' => [['id' => 21, 'missing' => 'region'], ['id' => 22, 'missing' => 'drop']],
        'region' => 'mazowieckie',
        'phrase' => 'uszkodzon*',
    ]));
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

test('dokładny wynik: precyzja i czułość 1,0, kod 0, 100 pomiarów took', function () {
    $fake = fakeVerifyReader([13, 11, 12]);

    $this->artisan('seller360:query:verify')
        ->expectsOutputToContain('Precyzja: 1.000')
        ->expectsOutputToContain('Czułość: 1.000')
        ->expectsOutputToContain('Bliskie pudła w wyniku: 0')
        ->expectsOutputToContain('p50 7 ms, p99 7 ms')
        ->assertExitCode(0);

    // 1 zapytanie kontrolne + 100 pomiarów, wszystkie z presetem DoD.
    expect($fake->calls)->toBe(101)
        ->and($fake->lastBody['query'])->toEqual(Seller360Query::fromFilters(Seller360Query::dodPreset())['query']);
});

test('trafione bliskie pudło daje kod 1', function () {
    fakeVerifyReader([11, 12, 13, 22]);

    $this->artisan('seller360:query:verify', ['--runs' => 1])
        ->expectsOutputToContain('Bliskie pudła w wyniku: 1')
        ->expectsOutputToContain('22 (brakuje: drop)')
        ->assertExitCode(1);
});

test('brak zasadzonego daje czułość poniżej 1 i kod 1', function () {
    fakeVerifyReader([11, 12]);

    $this->artisan('seller360:query:verify', ['--runs' => 1])
        ->expectsOutputToContain('Czułość: 0.667')
        ->expectsOutputToContain('Pominięte: 13')
        ->assertExitCode(1);
});

test('bez planted.json kod 1 z podpowiedzią', function () {
    File::delete($this->dir.'/planted.json');
    fakeVerifyReader([]);

    $this->artisan('seller360:query:verify')
        ->expectsOutputToContain('seller360:simulate')
        ->assertExitCode(1);
});
