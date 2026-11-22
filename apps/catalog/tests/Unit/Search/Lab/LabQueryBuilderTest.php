<?php

use App\Services\ProductSearchService;
use App\Services\Search\Lab\LabQueryBuilder;
use App\Services\Search\Lab\LabVariant;
use Elastic\Elasticsearch\ClientBuilder;

beforeEach(function () {
    // `buildTextQuery` nie dotyka ES — klient bez połączenia wystarcza.
    $this->search = new ProductSearchService(ClientBuilder::create()->setHosts(['http://es.invalid:9200'])->build());
    $this->builder = new LabQueryBuilder($this->search);
});

test('bm25 to dokładnie zapytanie tekstowe sklepu', function () {
    $body = $this->builder->body(LabVariant::Bm25, 'kurtka zimowa');

    expect($body['query'])->toBe($this->search->buildTextQuery('kurtka zimowa'))
        ->and($body['size'])->toBe(10);
});

test('knn na polu semantic_e5 z k=10 i num_candidates=100', function () {
    $knn = $this->builder->body(LabVariant::Knn, 'ciepła kurtka')['query']['knn'];

    expect($knn['field'])->toBe('semantic_e5')
        ->and($knn['k'])->toBe(10)
        ->and($knn['num_candidates'])->toBe(100)
        ->and($knn['query_vector_builder']['text_embedding'])->toBe(['model_id' => 'lab-e5', 'model_text' => 'ciepła kurtka']);
});

test('knnOn: zapytanie knn na jawnym polu wektorowym z query_vector_builder', function (string $field) {
    $knn = $this->builder->knnOn($field, 'ciepła kurtka')['knn'];

    expect($knn['field'])->toBe($field)
        ->and($knn['k'])->toBe(10)
        ->and($knn['num_candidates'])->toBe(100)
        ->and($knn['query_vector_builder']['text_embedding'])->toBe(['model_id' => 'lab-e5', 'model_text' => 'ciepła kurtka']);
})->with(['vec_float', 'vec_int8', 'vec_bbq']);

test('knnOn odrzuca pole spoza vec_float, vec_int8, vec_bbq', function () {
    $this->builder->knnOn('semantic_e5', 'x');
})->throws(InvalidArgumentException::class);

test('knnBody: filtr w knn, post_filter albo bez filtra', function () {
    $none = $this->builder->knnBody('q');
    $inside = $this->builder->knnBody('q', filter: LabQueryBuilder::FILTER_IN_KNN);
    $post = $this->builder->knnBody('q', filter: LabQueryBuilder::FILTER_POST);

    expect($none)->toBe($this->builder->body(LabVariant::Knn, 'q'))
        ->and($inside['query']['knn']['filter'])->toBe(['term' => ['in_stock' => true]])
        ->and($inside)->not->toHaveKey('post_filter')
        ->and($post['post_filter'])->toBe(['term' => ['in_stock' => true]])
        ->and($post['query']['knn'])->not->toHaveKey('filter');
});

test('knnBody na polu wektorowym używa knnOn', function () {
    expect($this->builder->knnBody('q', field: 'vec_bbq')['query'])->toBe($this->builder->knnOn('vec_bbq', 'q'));
});

test('numCandidates zmienia knn we wszystkich wariantach i polach', function () {
    $this->builder->useNumCandidates(500);

    expect($this->builder->body(LabVariant::Knn, 'q')['query']['knn']['num_candidates'])->toBe(500)
        ->and($this->builder->body(LabVariant::HybridBool, 'q')['query']['bool']['should'][1]['knn']['num_candidates'])->toBe(500)
        ->and($this->builder->body(LabVariant::RrfNative, 'q')['retriever']['rrf']['retrievers'][1]['standard']['query']['knn']['num_candidates'])->toBe(500)
        ->and($this->builder->knnOn('vec_bbq', 'q')['knn']['num_candidates'])->toBe(500)
        ->and($this->builder->knnBody('q')['query']['knn']['k'])->toBe(10);
});

test('numCandidates nie może być mniejsze niż k', function () {
    $this->builder->useNumCandidates(5);
})->throws(InvalidArgumentException::class);

test('hybrid-bool ma oba człony w should', function () {
    $should = $this->builder->body(LabVariant::HybridBool, 'ciepła kurtka')['query']['bool']['should'];

    expect($should)->toHaveCount(2)
        ->and($should[0])->toBe($this->search->buildTextQuery('ciepła kurtka'))
        ->and($should[1])->toHaveKey('knn');
});

test('rrf-native: retriever rrf z dwoma retrieverami, rank_constant=60, rank_window_size=100', function () {
    $rrf = $this->builder->body(LabVariant::RrfNative, 'ciepła kurtka')['retriever']['rrf'];

    expect($rrf['rank_constant'])->toBe(60)
        ->and($rrf['rank_window_size'])->toBe(100)
        ->and($rrf['retrievers'])->toHaveCount(2)
        ->and($rrf['retrievers'][0]['standard']['query'])->toBe($this->search->buildTextQuery('ciepła kurtka'))
        ->and($rrf['retrievers'][1]['standard']['query'])->toHaveKey('knn');
});

test('knn-exact: script_score z cosineSimilarity po vec_float', function () {
    $body = $this->builder->knnExactBody([0.1, 0.2], 10);

    expect($body['query']['script_score']['script']['source'])->toContain("cosineSimilarity(params.query_vector, 'vec_float')")
        ->and($body['query']['script_score']['script']['params']['query_vector'])->toBe([0.1, 0.2]);
});

test('wyrażalność jako query i licencja', function () {
    expect(LabVariant::Bm25->isQueryExpressible())->toBeTrue()
        ->and(LabVariant::HybridBool->isQueryExpressible())->toBeTrue()
        ->and(LabVariant::RrfNative->isQueryExpressible())->toBeFalse()
        ->and(LabVariant::RrfClient->isQueryExpressible())->toBeFalse()
        ->and(LabVariant::Bm25->requiresTrial())->toBeFalse()
        ->and(LabVariant::RrfNative->requiresTrial())->toBeTrue();
});

test('elser to zapytanie semantic na polu semantic_elser', function () {
    $body = $this->builder->body(LabVariant::Elser, 'ciepła kurtka');

    expect($body['query'])->toBe(['semantic' => ['field' => 'semantic_elser', 'query' => 'ciepła kurtka']])
        ->and($body['size'])->toBe(10);
});

test('elser celuje w products-lab-elser, pozostałe warianty w products-lab-v1', function () {
    expect($this->builder->indexFor(LabVariant::Elser))->toBe('products-lab-elser');

    foreach ([LabVariant::Bm25, LabVariant::Knn, LabVariant::HybridBool, LabVariant::RrfClient, LabVariant::RrfNative] as $variant) {
        expect($this->builder->indexFor($variant))->toBe('products-lab-v1');
    }
});
