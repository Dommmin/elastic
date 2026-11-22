<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Seller360\Seller360Card;
use App\Seller360\Seller360Query;
use App\Seller360\Seller360Reader;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Elastic\Transport\Exception\TransportException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ekran konsultanta (ETAP 13): wyszukiwarka sprzedawców i karta z sześcioma sekcjami
 * `seller-360`. Czyta wyłącznie ES; gdy ES leży (transport, 5xx), lista i karta mówią o tym komunikatem,
 * a nie 500. Odrzucone zapytanie (4xx) to osobny komunikat — ES działa, to zapytanie jest złe.
 */
class Seller360Controller extends Controller
{
    private const REGIONS = [
        'dolnośląskie', 'kujawsko-pomorskie', 'lubelskie', 'lubuskie', 'łódzkie', 'małopolskie',
        'mazowieckie', 'opolskie', 'podkarpackie', 'podlaskie', 'pomorskie', 'śląskie',
        'świętokrzyskie', 'warmińsko-mazurskie', 'wielkopolskie', 'zachodniopomorskie',
    ];

    private const COURIERS = 8;

    /** Okno `from + size` w ES (`index.max_result_window`, domyślnie 10 000): dalsza strona to 400. */
    private const MAX_RESULT_WINDOW = 10000;

    public function index(Request $request, Seller360Reader $reader): Response
    {
        $filters = [
            'q' => (string) $request->query('q', ''),
            'region' => (string) $request->query('region', ''),
            'vat_status' => (string) $request->query('vat_status', ''),
            'overdue' => $request->boolean('overdue'),
            'min_drop' => is_numeric($request->query('min_drop')) ? (float) $request->query('min_drop') : null,
            'complaint_phrase' => (string) $request->query('complaint_phrase', ''),
            'courier' => (string) $request->query('courier', ''),
            'sort' => $request->query('sort') === 'gmv' ? 'gmv' : '',
        ];
        $page = min(max(1, (int) $request->query('page', '1')), intdiv(self::MAX_RESULT_WINDOW, Seller360Query::DEFAULT_SIZE));

        $result = ['took' => null, 'total' => 0, 'hits' => []];
        $esAvailable = true;
        $queryError = null;
        try {
            $result = $reader->search(Seller360Query::fromFilters($filters, $page));
        } catch (TransportException|ServerResponseException $e) {
            Log::warning('Seller 360: ES nieosiągalny', ['error' => $e->getMessage()]);
            $esAvailable = false;
        } catch (ClientResponseException $e) {
            Log::warning('Seller 360: ES odrzucił zapytanie', ['status' => $e->getCode(), 'error' => $e->getMessage()]);
            $queryError = $e->getCode();
        }

        return Inertia::render('panel/sellers360/Index', [
            'sellers' => array_map(
                static fn (array $hit): array => Seller360Card::row((int) $hit['id'], $hit['source']),
                $result['hits'],
            ),
            'total' => $result['total'],
            'took' => $result['took'],
            'page' => $page,
            'perPage' => Seller360Query::DEFAULT_SIZE,
            'es_available' => $esAvailable,
            'query_error' => $queryError,
            'filters' => $filters,
            'dodPreset' => Seller360Query::dodPreset(),
            'options' => [
                'regions' => self::REGIONS,
                'couriers' => array_map(static fn (int $n): string => 'courier-'.$n, range(1, self::COURIERS)),
            ],
        ]);
    }

    public function show(int $seller, Seller360Reader $reader): Response
    {
        try {
            $source = $reader->find($seller);
        } catch (TransportException|ServerResponseException $e) {
            Log::warning('Seller 360: ES nieosiągalny', ['error' => $e->getMessage()]);

            return Inertia::render('panel/sellers360/Show', ['seller' => null, 'sellerId' => $seller, 'es_available' => false]);
        }
        abort_if($source === null, 404);

        return Inertia::render('panel/sellers360/Show', [
            'seller' => Seller360Card::fromSource($seller, $source),
            'sellerId' => $seller,
            'es_available' => true,
        ]);
    }
}
