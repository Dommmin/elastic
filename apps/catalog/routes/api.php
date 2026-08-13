<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\Internal\ProductProjectionController;
use Illuminate\Support\Facades\Route;

// Readiness (docs/02-APLIKACJE.md) — patrz komentarz w HealthController.
// Uwaga: `/up` (liveness, wbudowane w Laravela) jest zarejestrowane osobno
// w bootstrap/app.php i to JEGO używa Docker HEALTHCHECK, nie tego.
Route::get('/health', HealthController::class);

// "internal" — wywoływane WYŁĄCZNIE przez search-service (Symfony) z sieci
// dockerowej, nigdy z przeglądarki. Docs/02-APLIKACJE.md, sekcja 7.1,
// wariant B (read-back API). Prawdziwa izolacja (API keys) w ETAPIE 12.
Route::prefix('internal')->group(function () {
    Route::get('/products/{id}/projection', [ProductProjectionController::class, 'show']);
});
