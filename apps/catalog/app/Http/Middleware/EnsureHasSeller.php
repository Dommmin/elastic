<?php

namespace App\Http\Middleware;

use App\Http\CurrentSeller;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Portal sprzedawcy wymaga profilu sprzedawcy: bez niego jedyną sensowną
 * stroną jest formularz zgłoszenia.
 */
class EnsureHasSeller
{
    public function __construct(private CurrentSeller $currentSeller) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->currentSeller->of($request) === null) {
            return redirect()->route('seller.apply');
        }

        return $next($request);
    }
}
