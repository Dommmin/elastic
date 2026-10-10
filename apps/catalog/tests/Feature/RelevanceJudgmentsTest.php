<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\Search\RelevanceJudgments;

/**
 * Reguły z tests/relevance/queries.yaml → `id => grade` dla `_rank_eval`
 * (`search:eval`). Bez ES — prawda pochodzi z bazy katalogu.
 */
beforeEach(function () {
    $phones = Category::factory()->create(['name' => 'Smartfony']);
    $laptops = Category::factory()->create(['name' => 'Laptopy']);
    $acme = Brand::factory()->create(['name' => 'Acme PLC']);
    $other = Brand::factory()->create(['name' => 'Other Ltd']);

    $this->phoneA = Product::factory()->create(['name' => 'Acme PLC Smartfon Nova 128GB', 'category_id' => $phones->id, 'brand_id' => $acme->id]);
    $this->phoneB = Product::factory()->create(['name' => 'Other Ltd Smartfon Pro', 'category_id' => $phones->id, 'brand_id' => $other->id]);
    $this->laptop = Product::factory()->create(['name' => 'Acme PLC Laptop Ultra 14"', 'category_id' => $laptops->id, 'brand_id' => $acme->id]);
});

test('reguła kategorii ocenia całą grupę, nie wybrane ID-ki', function () {
    $ratings = (new RelevanceJudgments)->resolve([['category' => 'Smartfony', 'grade' => 1]]);

    expect($ratings)->toBe([
        (string) $this->phoneA->id => 1,
        (string) $this->phoneB->id => 1,
    ]);
});

test('dokument pasujący do kilku reguł dostaje najwyższą ocenę', function () {
    $ratings = (new RelevanceJudgments)->resolve([
        ['brand' => 'Acme PLC', 'grade' => 1],
        ['name' => 'Acme PLC Laptop Ultra 14"', 'grade' => 3],
        ['name_contains' => 'smartfon nova', 'grade' => 2],
    ]);

    expect($ratings)->toEqual([
        (string) $this->phoneA->id => 2,
        (string) $this->laptop->id => 3,
    ]);
});

test('reguła, która nic nie łapie, przerywa ewaluację', function () {
    (new RelevanceJudgments)->resolve([['category' => 'Nie ma takiej', 'grade' => 1]]);
})->throws(InvalidArgumentException::class, 'nie pasuje do żadnego produktu');

test('reguła musi mieć dokładnie jeden selektor', function () {
    (new RelevanceJudgments)->resolve([['brand' => 'Acme PLC', 'category' => 'Smartfony', 'grade' => 1]]);
})->throws(InvalidArgumentException::class, 'dokładnie jeden selektor');
