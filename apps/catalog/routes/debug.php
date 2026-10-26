<?php

use App\Support\ErrorTracking\SmokeMarker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Trasy `make errors-smoke` (ETAP 11b)
|--------------------------------------------------------------------------
|
| Wywołują błąd na życzenie, żeby sprawdzić drogę do GlitchTipa z żądania HTTP
| i z przeglądarki. Ładowane WYŁĄCZNIE w APP_ENV=local (bootstrap/app.php).
*/

Route::get('_debug/errors/http', function (Request $request): never {
    throw new RuntimeException('errors-smoke:http:'.SmokeMarker::clean($request->query('m')));
});

Route::get('_debug/errors/frontend', fn (Request $request) => Inertia::render('debug/ErrorsSmoke', [
    'marker' => SmokeMarker::clean($request->query('m')),
]));
