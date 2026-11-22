<?php

namespace App\Services\Search\Lab;

use App\Services\ProductSearchService;
use InvalidArgumentException;
use LogicException;

/**
 * Ciała `_search` wariantów laboratorium na `products-lab-v1` (węzeł es-lab).
 *
 * kNN na polu `semantic_text` (`semantic_e5`) idzie jako ZAPYTANIE `knn` w `query`
 * z `query_vector_builder.text_embedding` — sprawdzone na ES 9.5.1: sekcja `knn`
 * najwyższego poziomu i retriever `knn` odrzucają `semantic_text`
 * („[knn] queries are only supported on [dense_vector] fields”), a zapytanie `knn`
 * obsługuje je (wektor zapytania liczy endpoint pola, E5 dokłada prefiks `query: `).
 * Dzięki temu `knn`, `hybrid-bool` i oba RRF celują w to samo pole tym samym
 * zapytaniem, a `knn` i `hybrid-bool` są zwykłym `query` — `_rank_eval` je przyjmuje.
 */
class LabQueryBuilder
{
    public const INDEX = 'products-lab-v1';

    public const INFERENCE_ID = 'lab-e5';

    public const SEMANTIC_FIELD = 'semantic_e5';

    public const K = 10;

    /** Stałe dla wszystkich zapytań: 10× k, typowy punkt startowy z dokumentacji ES. */
    public const NUM_CANDIDATES = 100;

    public const RANK_CONSTANT = 60;

    public const RANK_WINDOW = 100;

    /** Jawne pola wektorowe (te same wektory, różne `index_options`) — koszt i jakość kwantyzacji. */
    public const VECTOR_FIELDS = ['vec_float', 'vec_int8', 'vec_bbq'];

    /** Tryby filtra dostępności w wariancie `knn` (demo filtrowanego kNN). */
    public const FILTER_NONE = 'none';

    public const FILTER_IN_KNN = 'in-knn';

    public const FILTER_POST = 'post';

    public const FILTER_MODES = [self::FILTER_NONE, self::FILTER_IN_KNN, self::FILTER_POST];

    public function __construct(private readonly ProductSearchService $search) {}

    /**
     * @return array<string, mixed> pełne ciało `_search` (`query` albo `retriever`)
     */
    public function body(LabVariant $variant, string $q, int $size = self::K): array
    {
        $body = match ($variant) {
            LabVariant::Bm25 => ['query' => $this->textQuery($q)],
            LabVariant::Knn => ['query' => $this->knnQuery($q)],
            LabVariant::HybridBool => ['query' => ['bool' => ['should' => [$this->textQuery($q), $this->knnQuery($q)]]]],
            LabVariant::RrfNative => ['retriever' => ['rrf' => [
                'retrievers' => [
                    ['standard' => ['query' => $this->textQuery($q)]],
                    ['standard' => ['query' => $this->knnQuery($q)]],
                ],
                'rank_constant' => self::RANK_CONSTANT,
                'rank_window_size' => self::RANK_WINDOW,
            ]]],
            LabVariant::RrfClient => throw new LogicException('rrf-client to dwa zapytania (bm25 i knn) łączone w PHP — patrz LabEvalRunner.'),
            LabVariant::KnnExact => throw new LogicException('knn-exact potrzebuje wektora zapytania — użyj knnExactBody().'),
            LabVariant::Elser => throw new LogicException('wariant elser wymaga make lab-setup-elser (ETAP 14b)'),
        };

        return $body + ['size' => $size, '_source' => false];
    }

    /**
     * Zapytanie tekstowe sklepu bez popularności (`buildTextQuery`) — linia bazowa `bm25`.
     *
     * @return array<string, mixed>
     */
    public function textQuery(string $q): array
    {
        return $this->search->buildTextQuery($q);
    }

    /**
     * @return array<string, mixed>
     */
    public function knnQuery(string $q): array
    {
        return ['knn' => [
            'field' => self::SEMANTIC_FIELD,
            'k' => self::K,
            'num_candidates' => self::NUM_CANDIDATES,
            'query_vector_builder' => ['text_embedding' => ['model_id' => self::INFERENCE_ID, 'model_text' => $q]],
        ]];
    }

    /**
     * Zapytanie `knn` na jawnym polu wektorowym (`vec_float|vec_int8|vec_bbq`) z wektorem
     * liczonym przez endpoint (`query_vector_builder`; na `dense_vector` E5 też dokłada `query: `).
     *
     * @return array<string, mixed>
     */
    public function knnOn(string $field, string $q): array
    {
        if (! in_array($field, self::VECTOR_FIELDS, true)) {
            throw new InvalidArgumentException("Pole „{$field}” nie jest jawnym polem wektorowym (".implode(', ', self::VECTOR_FIELDS).').');
        }

        $knn = $this->knnQuery($q);
        $knn['knn']['field'] = $field;

        return $knn;
    }

    /**
     * Ciało wariantu `knn` z wybranym polem i filtrem dostępności: `in-knn` = `filter` wewnątrz
     * `knn` (filtr działa PRZED wyborem k sąsiadów), `post` = `post_filter` (PO wyborze k sąsiadów,
     * więc wyników bywa mniej niż `size`).
     *
     * @return array<string, mixed>
     */
    public function knnBody(string $q, int $size = self::K, string $field = self::SEMANTIC_FIELD, string $filter = self::FILTER_NONE): array
    {
        if (! in_array($filter, self::FILTER_MODES, true)) {
            throw new InvalidArgumentException("Nieznany tryb filtra „{$filter}” (".implode(', ', self::FILTER_MODES).').');
        }

        $query = $field === self::SEMANTIC_FIELD ? $this->knnQuery($q) : $this->knnOn($field, $q);
        $stock = ['term' => ['in_stock' => true]];

        if ($filter === self::FILTER_IN_KNN) {
            $query['knn']['filter'] = $stock;
        }

        $body = ['query' => $query, 'size' => $size, '_source' => false];

        if ($filter === self::FILTER_POST) {
            $body['post_filter'] = $stock;
        }

        return $body;
    }

    /**
     * HNSW na jawnym `vec_float` z gotowym wektorem — do recall@10 względem `knn-exact`
     * (to samo pole i ten sam wektor, różni się tylko przybliżenie HNSW).
     *
     * @param  list<float>  $vector
     * @return array<string, mixed>
     */
    public function knnFloatBody(array $vector, int $size = self::K): array
    {
        return [
            'query' => ['knn' => [
                'field' => 'vec_float',
                'k' => self::K,
                'num_candidates' => self::NUM_CANDIDATES,
                'query_vector' => $vector,
            ]],
            'size' => $size,
            '_source' => false,
        ];
    }

    /**
     * Dokładny kNN (brute force) po `vec_float`: `cosineSimilarity + 1` (wynik nie może być
     * ujemny) na każdym dokumencie. Wektor z `_inference/text_embedding/lab-e5`
     * z `input_type: search` (E5 dokłada wtedy `query: `, jak `query_vector_builder`).
     *
     * @param  list<float>  $vector
     * @return array<string, mixed>
     */
    public function knnExactBody(array $vector, int $size = self::K): array
    {
        return [
            'query' => ['script_score' => [
                'query' => ['match_all' => new \stdClass],
                'script' => [
                    'source' => "cosineSimilarity(params.query_vector, 'vec_float') + 1.0",
                    'params' => ['query_vector' => $vector],
                ],
            ]],
            'size' => $size,
            '_source' => false,
        ];
    }
}
