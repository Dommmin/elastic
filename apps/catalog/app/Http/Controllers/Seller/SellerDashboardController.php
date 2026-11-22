<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\CurrentSeller;
use App\Seller360\SellerStatsReader;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use SellerPortal\Infrastructure\ReadModel\SellerDashboardStats;

class SellerDashboardController extends Controller
{
    public function __invoke(Request $request, CurrentSeller $currentSeller, SellerDashboardStats $stats, SellerStatsReader $seller360): Response
    {
        // Id wyłącznie z zalogowanego sprzedawcy, nigdy z parametru żądania (izolacja widoku, ETAP 13).
        $sellerId = $currentSeller->of($request)['id'];

        return Inertia::render('seller/Dashboard', [
            'stats' => $stats->forSeller($sellerId),
            // Deferred: wolny albo niedostępny ES nie spowalnia pierwszego malowania kafelków.
            'seller360' => Inertia::defer(fn () => $seller360->forSeller($sellerId)),
        ]);
    }
}
