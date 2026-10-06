<?php

use App\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

// ETAP 7 — nazwana, więc Wayfinder generuje typed `search()` do użycia
// w Vue (`resources/js/routes`), patrz docs/06-PLAN-WDROZENIA.md.
Route::get('search', [SearchController::class, 'index'])->name('search');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
