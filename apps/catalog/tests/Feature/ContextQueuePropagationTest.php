<?php

use App\Support\Tracing\TraceId;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

/**
 * Zadanie-sonda: zapamiętuje trace_id, jaki widzi w chwili wykonania.
 */
class TraceProbeJob implements ShouldQueue
{
    use Queueable;

    public static ?string $seen = null;

    public function handle(): void
    {
        self::$seen = Context::get(TraceId::CONTEXT_KEY);
    }
}

// Sterownik `database`, nie `sync`: przy sync zadanie biegnie w tym samym
// procesie i Context i tak by tam był — test niczego by nie dowodził.
// Z `database` payload trafia do tabeli `jobs` (Laravel dokłada do niego
// zrzut Contextu), potem czyścimy Context i wykonujemy zadanie workerem,
// który hydratuje Context z payloadu, tak jak robi to catalog-worker.
test('trace_id z Context dociera do zadania w kolejce', function () {
    $traceId = str_repeat('b', 32);
    TraceProbeJob::$seen = null;

    Context::add(TraceId::CONTEXT_KEY, $traceId);
    TraceProbeJob::dispatch()->onConnection('database');

    expect(DB::table('jobs')->count())->toBe(1);

    Context::flush();
    expect(Context::get(TraceId::CONTEXT_KEY))->toBeNull();

    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--stop-when-empty' => true]);

    expect(TraceProbeJob::$seen)->toBe($traceId);
});
