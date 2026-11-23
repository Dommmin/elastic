<?php

use App\Console\Commands\LabExportCommand;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\Search\Lab\LabJudgments;

/**
 * Oceny laboratoryjne = reguły z bazy przecięte z próbką `id % MODULO = 0`
 * (tylko te produkty są w `products-lab-v1`).
 */
beforeEach(function () {
    $m = LabExportCommand::MODULO;
    $jackets = Category::factory()->create(['name' => 'Kurtki']);
    $laptops = Category::factory()->create(['name' => 'Laptopy']);
    $brand = Brand::factory()->create();

    $this->inSample = Product::factory()->create(['id' => $m * 4, 'name' => 'Kurtka A', 'category_id' => $jackets->id, 'brand_id' => $brand->id]);
    $this->outOfSample = Product::factory()->create(['id' => $m * 4 + 1, 'name' => 'Kurtka B', 'category_id' => $jackets->id, 'brand_id' => $brand->id]);
    $this->laptopOut = Product::factory()->create(['id' => $m * 8 + 1, 'name' => 'Laptop C', 'category_id' => $laptops->id, 'brand_id' => $brand->id]);
});

function labYaml(string $body): string
{
    $path = tempnam(sys_get_temp_dir(), 'lab').'.yaml';
    file_put_contents($path, $body);

    return $path;
}

test('reguła daje oceny przecięte z próbką', function () {
    expect((new LabJudgments)->forQuery([['category' => 'Kurtki', 'grade' => 2]]))
        ->toBe([(string) $this->inSample->id => 2]);
});

test('puste oceny po przecięciu rzucają wyjątek z treścią zapytania', function () {
    (new LabJudgments)->forQuery([['category' => 'Laptopy', 'grade' => 1]], 'laptop na wynos');
})->throws(InvalidArgumentException::class, 'laptop na wynos');

test('load czyta plik i zwraca grupę oraz oceny', function () {
    $path = labYaml("queries:\n  - query: kurtka\n    group: lexical\n    relevant:\n      - { category: Kurtki, grade: 3 }\n");

    expect((new LabJudgments)->load($path))->toBe([
        ['query' => 'kurtka', 'group' => 'lexical', 'ratings' => [(string) $this->inSample->id => 3]],
    ]);
});

test('nieznana grupa rzuca wyjątek', function () {
    $path = labYaml("queries:\n  - query: kurtka\n    group: magic\n    relevant:\n      - { category: Kurtki, grade: 3 }\n");

    (new LabJudgments)->load($path);
})->throws(InvalidArgumentException::class, 'magic');

test('load wskazuje zapytanie, które po przecięciu nie ma ocen', function () {
    $path = labYaml("queries:\n  - query: laptop na wynos\n    group: descriptive\n    relevant:\n      - { category: Laptopy, grade: 2 }\n");

    (new LabJudgments)->load($path);
})->throws(InvalidArgumentException::class, 'laptop na wynos');
