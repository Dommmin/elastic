<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\CurrentSeller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use SellerPortal\Infrastructure\ReadModel\SellerDashboardStats;

class SellerDashboardController extends Controller
{
    public function __invoke(Request $request, CurrentSeller $currentSeller, SellerDashboardStats $stats): Response
    {
        return Inertia::render('seller/Dashboard', [
            'stats' => $stats->forSeller($currentSeller->of($request)['id']),
        ]);
    }
}
