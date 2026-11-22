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

test('elser czeka na ETAP 14b', function () {
    $this->builder->body(LabVariant::Elser, 'x');
})->throws(LogicException::class, 'wariant elser wymaga make lab-setup-elser (ETAP 14b)');
