<?php

use App\Http\Controllers\Panel\ProductController;
use App\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

// ETAP 7 — nazwana, więc Wayfinder generuje typed `search()` do użycia
// w Vue (`resources/js/routes`), patrz docs/06-PLAN-WDROZENIA.md.
Route::get('search', [SearchController::class, 'index'])->name('search');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

// Panel edycji katalogu. Bez `verified` — konta zakłada `marketplace:admin`,
// bez potwierdzania e-maila. Kolejne zadania dokładają trasy do tej grupy.
Route::middleware('auth')->prefix('panel')->name('panel.')->group(function () {
    Route::get('products', [ProductController::class, 'index'])->name('products.index');
});

require __DIR__.'/settings.php';
