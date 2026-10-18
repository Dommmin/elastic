<?php

namespace App\Http\Middleware;

use App\Support\Tracing\TraceId;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nadaje żądaniu trace_id (z poprawnego `traceparent` albo nowy) i zwraca go
 * w `X-Trace-Id`, żeby człowiek z błędem na ekranie mógł podać id do logów.
 * Globalny (prepend), więc obejmuje też /up, /api/internal/* i webhooki.
 */
class AssignTraceId
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $traceId = TraceId::fromTraceparent($request->header('traceparent')) ?? TraceId::generate();

        Context::add(TraceId::CONTEXT_KEY, $traceId);

        $response = $next($request);
        $response->headers->set('X-Trace-Id', $traceId);

        return $response;
    }
}
