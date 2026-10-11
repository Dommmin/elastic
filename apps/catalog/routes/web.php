<?php

use App\Http\Controllers\Panel\OfferController;
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

// Panel edycji katalogu: `can:panel` (flaga is_admin), bo rejestracja jest
// otwarta. Bez `verified` — konta zakłada `marketplace:admin`,
// bez potwierdzania e-maila. Kolejne zadania dokładają trasy do tej grupy.
Route::middleware(['auth', 'can:panel'])->prefix('panel')->name('panel.')->group(function () {
    Route::get('products', [ProductController::class, 'index'])->name('products.index');
    Route::get('products/{product}', [ProductController::class, 'edit'])->name('products.edit');
    Route::put('products/{product}', [ProductController::class, 'update'])->name('products.update');
    Route::put('offers/{offer}', [OfferController::class, 'update'])->name('offers.update');
    Route::post('offers/{offer}/deactivate', [OfferController::class, 'deactivate'])->name('offers.deactivate');
});

require __DIR__.'/settings.php';
