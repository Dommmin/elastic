<?php

use App\Http\Controllers\Panel\Moderation\SellerModerationController;
use App\Http\Controllers\Panel\ProductController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\Seller\ProductLookupController;
use App\Http\Controllers\Seller\SellerApplicationController;
use App\Http\Controllers\Seller\SellerDashboardController;
use App\Http\Controllers\Seller\SellerOfferController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

// ETAP 7 — nazwana, więc Wayfinder generuje typed `search()` do użycia
// w Vue (`resources/js/routes`), patrz docs/06-PLAN-WDROZENIA.md.
Route::get('search', [SearchController::class, 'index'])->name('search');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    // Portal sprzedawcy: zgłoszenie wymaga potwierdzonego e-maila, bo
    // moderator kontaktuje się ze sprzedawcą właśnie przez niego.
    Route::get('seller/apply', [SellerApplicationController::class, 'create'])->name('seller.apply');
    Route::post('seller/apply', [SellerApplicationController::class, 'store'])->name('seller.apply.store');
});

Route::middleware(['auth', 'seller'])->group(function () {
    Route::get('seller/status', [SellerApplicationController::class, 'status'])->name('seller.status');
});

// Portal sprzedawcy: aktywny i zablokowany (ten drugi tylko ogląda i wycofuje
// — resztę odrzuca warstwa aplikacji). `{offer}` to ID; właściciela sprawdza
// `SellerOfferAccess`, cudza oferta kończy się 404.
Route::middleware(['auth', 'seller', 'seller.approved'])->prefix('seller')->name('seller.')->group(function () {
    Route::get('/', SellerDashboardController::class)->name('dashboard');
    Route::get('products/lookup', ProductLookupController::class)->name('products.lookup');

    Route::get('offers', [SellerOfferController::class, 'index'])->name('offers.index');
    Route::get('offers/create', [SellerOfferController::class, 'create'])->name('offers.create');
    Route::post('offers', [SellerOfferController::class, 'store'])->name('offers.store');

    Route::whereNumber('offer')->group(function () {
        Route::get('offers/{offer}', [SellerOfferController::class, 'show'])->name('offers.show');
        Route::post('offers/{offer}/publish', [SellerOfferController::class, 'publish'])->name('offers.publish');
        Route::put('offers/{offer}/price', [SellerOfferController::class, 'updatePrice'])->name('offers.price');
        Route::delete('offers/{offer}/pending-price', [SellerOfferController::class, 'cancelPendingPrice'])->name('offers.pending-price.destroy');
        Route::put('offers/{offer}/stock', [SellerOfferController::class, 'updateStock'])->name('offers.stock');
        Route::put('offers/{offer}/details', [SellerOfferController::class, 'updateDetails'])->name('offers.details');
        Route::post('offers/{offer}/withdraw', [SellerOfferController::class, 'withdraw'])->name('offers.withdraw');
    });
});

// Panel edycji katalogu: `can:panel` (flaga is_admin), bo rejestracja jest
// otwarta. Bez `verified` — konta zakłada `marketplace:admin`,
// bez potwierdzania e-maila. Kolejne zadania dokładają trasy do tej grupy.
Route::middleware(['auth', 'can:panel'])->prefix('panel')->name('panel.')->group(function () {
    Route::get('products', [ProductController::class, 'index'])->name('products.index');
    Route::get('products/{product}', [ProductController::class, 'edit'])->name('products.edit');
    Route::put('products/{product}', [ProductController::class, 'update'])->name('products.update');

    Route::get('sellers', [SellerModerationController::class, 'index'])->name('sellers.index');
    Route::post('sellers/{seller}/approve', [SellerModerationController::class, 'approve'])->whereNumber('seller')->name('sellers.approve');
    Route::post('sellers/{seller}/reject', [SellerModerationController::class, 'reject'])->whereNumber('seller')->name('sellers.reject');
    Route::post('sellers/{seller}/block', [SellerModerationController::class, 'block'])->whereNumber('seller')->name('sellers.block');
    Route::post('sellers/{seller}/unblock', [SellerModerationController::class, 'unblock'])->whereNumber('seller')->name('sellers.unblock');
});

require __DIR__.'/settings.php';
