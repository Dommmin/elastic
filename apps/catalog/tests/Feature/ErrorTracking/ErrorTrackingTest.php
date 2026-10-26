<?php

use App\Models\User;
use App\Support\ErrorTracking\QueueRetryFilter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Ordering\Application\Order\CartEmpty;
use Sentry\Event;
use Tests\Support\FakeSentryTransport;
use Tests\TestCase;

/**
 * Zadanie, które zawsze rzuca. `$tries` ustawia test (2 = jedna próba ponowiona).
 */
final class ErrorTrackingProbeJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public function __construct(public int $tries = 1) {}

    public function handle(): void
    {
        throw new RuntimeException('job boom');
    }
}

beforeAll(function () {
    TestCase::$sentryDsn = 'http://00000000000000000000000000000001@glitchtip/1';
});

afterAll(function () {
    TestCase::$sentryDsn = null;
});

beforeEach(function () {
    FakeSentryTransport::reset();
    Route::get('/__boom', fn () => throw new RuntimeException('boom'));
    Route::post('/__boom-post', fn () => throw new RuntimeException('boom'));
});

/**
 * Funkcja z argumentem-tablicą, jak `CreateNewUser::create(array $input)`: bez
 * `zend.exception_ignore_args` jej argumenty wchodzą do śladu wyjątku.
 */
function errorTrackingThrowWithArgs(array $input): never
{
    throw new RuntimeException('boom with args');
}

/** @return list<Event> */
function sentEvents(): array
{
    return FakeSentryTransport::$events;
}

function runQueueOnce(): void
{
    Artisan::call('queue:work', ['--once' => true, '--queue' => 'default', '--tries' => 1, '--sleep' => 0]);
}

test('wyjątek w żądaniu HTTP trafia do GlitchTipa z trace_id równym X-Trace-Id', function () {
    $response = $this->get('/__boom');

    $response->assertStatus(500);
    expect(sentEvents())->toHaveCount(1);

    $tags = sentEvents()[0]->getTags();
    expect($tags['trace_id'])->toBe($response->headers->get('X-Trace-Id'))
        ->and($tags['process'])->toBe('http');
});

test('zdarzenie ma user.id, ale nie ma e-maila ani adresu IP', function () {
    $user = User::factory()->create(['email' => 'jan.kowalski@example.com']);

    $this->actingAs($user)->get('/__boom');

    $sentUser = sentEvents()[0]->getUser();
    expect($sentUser?->getId())->toBe((string) $user->id)
        ->and($sentUser->getEmail())->toBeNull()
        ->and($sentUser->getIpAddress())->toBeNull()
        ->and($sentUser->getUsername())->toBeNull();
});

test('zdarzenie nie niesie treści żądania, ciasteczek ani e-maila z formularza', function () {
    $this->withCookie('laravel_session', 'cookie-secret-value')
        ->post('/__boom-post', ['password' => 'secret123', 'email' => 'jan.kowalski@example.com']);

    $serialized = json_encode(sentEvents()[0]->getRequest());

    expect($serialized)->not->toContain('secret123')
        ->and($serialized)->not->toContain('jan.kowalski@example.com')
        ->and($serialized)->not->toContain('cookie-secret-value');
});

test('404, walidacja i błąd domeny w HTTP nie są raportowane', function () {
    Route::post('/__validate', fn () => request()->validate(['name' => 'required']));
    Route::get('/__domain', fn () => throw new CartEmpty);

    // bootstrap/app.php pomija błędy domeny tylko POZA konsolą. Octane ustawia
    // APP_RUNNING_IN_CONSOLE=false dla żądań HTTP, a proces testowy jest konsolą,
    // więc symulujemy to, co widzi serwer.
    (new ReflectionProperty($this->app, 'isRunningInConsole'))->setValue($this->app, false);

    $this->get('/__nie-ma-takiej-strony')->assertNotFound();
    $this->postJson('/__validate', [])->assertUnprocessable();
    $this->get('/__domain');

    expect(sentEvents())->toBeEmpty();
});

test('wyjątek zadania z kolejki trafia do GlitchTipa z trace_id z Context', function () {
    config(['queue.default' => 'database']);
    Context::add('trace_id', str_repeat('a', 32));

    dispatch(new ErrorTrackingProbeJob(tries: 1));
    Context::flush();
    runQueueOnce();

    expect(sentEvents())->toHaveCount(1)
        ->and(sentEvents()[0]->getTags()['trace_id'])->toBe(str_repeat('a', 32));
});

test('próba zadania, która zostanie ponowiona, nie jest raportowana, ostatnia tak', function () {
    config(['queue.default' => 'database']);

    dispatch(new ErrorTrackingProbeJob(tries: 2));
    runQueueOnce();
    expect(sentEvents())->toBeEmpty();
    expect(DB::table('jobs')->count())->toBe(1);

    runQueueOnce();
    expect(sentEvents())->toHaveCount(1);
    expect(DB::table('failed_jobs')->count())->toBe(1);
});

test('wyjątek spoza zadania po ponowionej próbie nadal jest raportowany', function () {
    config(['queue.default' => 'database']);

    dispatch(new ErrorTrackingProbeJob(tries: 2));
    runQueueOnce();
    expect(sentEvents())->toBeEmpty();

    $this->get('/__boom');

    expect(sentEvents())->toHaveCount(1);
});

test('trace_id i użytkownik jednego żądania nie przeciekają do następnego', function () {
    $user = User::factory()->create();
    $first = '00-'.str_repeat('1', 32).'-'.str_repeat('2', 16).'-01';
    $second = '00-'.str_repeat('3', 32).'-'.str_repeat('4', 16).'-01';

    $this->actingAs($user)->get('/__boom', ['traceparent' => $first]);
    auth()->logout();
    $this->flushSession();
    $this->get('/__boom', ['traceparent' => $second]);

    expect(sentEvents())->toHaveCount(2)
        ->and(sentEvents()[0]->getTags()['trace_id'])->toBe(str_repeat('1', 32))
        ->and(sentEvents()[1]->getTags()['trace_id'])->toBe(str_repeat('3', 32))
        ->and(sentEvents()[1]->getUser()?->getId())->toBeNull();
});

test('konfiguracja: bez DSN w środowisku klient jest wyłączony', function () {
    $config = require config_path('sentry.php');

    expect($config['dsn'])->toBeNull();
});

test('konfiguracja: transport ma krótkie timeouty, bez PII, śladów i body żądania', function () {
    expect(config('sentry.http_connect_timeout'))->toBe(0.3)
        ->and(config('sentry.http_timeout'))->toBe(1.0)
        ->and(config('sentry.send_default_pii'))->toBeFalse()
        ->and(config('sentry.traces_sample_rate'))->toBe(0.0)
        ->and(config('sentry.max_request_body_size'))->toBe('never')
        ->and(config('sentry.enable_logs'))->toBeFalse();
});

test('QueueRetryFilter nie wycisza wyjątku, o którym nic nie wie', function () {
    expect(app(QueueRetryFilter::class)->willRetry(new RuntimeException('x')))->toBeFalse();
});

test('ramki stosu nie niosą wartości argumentów, więc hasło z formularza nie wycieka', function () {
    // Obraz FrankenPHP nie ma php.ini, więc `zend.exception_ignore_args` jest tam Off.
    ini_set('zend.exception_ignore_args', '0');
    Route::post('/__args', fn () => errorTrackingThrowWithArgs([
        'email' => 'jan.kowalski@example.com',
        'password' => 'Plaintext#123',
    ]));

    $this->post('/__args');

    $frames = [];
    foreach (sentEvents()[0]->getExceptions() as $exception) {
        foreach ($exception->getStacktrace()?->getFrames() ?? [] as $frame) {
            $frames[] = ['function' => $frame->getFunctionName(), 'vars' => $frame->getVars()];
        }
    }
    $serialized = json_encode($frames);

    expect(collect($frames)->pluck('function')->implode(' '))->toContain('errorTrackingThrowWithArgs')
        ->and($serialized)->not->toContain('Plaintext#123')
        ->and($serialized)->not->toContain('jan.kowalski');
});

test('adres i nagłówki zdarzenia nie niosą tokenów ani e-maila z URL', function () {
    Route::get('/reset-password/{token}', fn () => throw new RuntimeException('boom'));

    $this->get('/reset-password/SECRETTOKEN123?email=jan.kowalski%40example.com', [
        'Referer' => 'https://catalog.localhost/email/verify/7/HASHSECRET?expires=1&signature=SIGSECRET',
        'X-XSRF-TOKEN' => 'xsrf-secret-value',
    ]);

    $request = sentEvents()[0]->getRequest();
    $serialized = json_encode($request);

    expect($request['url'] ?? '')->toContain('/reset-password/')
        ->and($serialized)->not->toContain('SECRETTOKEN123')
        ->and($serialized)->not->toContain('jan.kowalski')
        ->and($serialized)->not->toContain('HASHSECRET')
        ->and($serialized)->not->toContain('SIGSECRET')
        ->and($serialized)->not->toContain('xsrf-secret-value');
});

test('komunikat błędu SQL nie niesie powiązanych wartości, DETAIL ani zapytania', function () {
    Route::get('/__sql', fn () => throw new QueryException(
        'pgsql',
        'insert into "users" ("email") values (?)',
        ['jan.kowalski@example.com'],
        new PDOException('SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "users_email_unique"'."\nDETAIL:  Key (email)=(jan.kowalski@example.com) already exists."),
    ));

    $this->get('/__sql');

    $messages = implode("\n", array_map(fn ($e) => $e->getValue(), sentEvents()[0]->getExceptions()));

    expect($messages)->toContain('users_email_unique')
        ->and($messages)->not->toContain('jan.kowalski')
        ->and($messages)->not->toContain('insert into');
});

test('konfiguracja zbierania danych wyłącza zmienne ramek, query string, ciasteczka i body', function () {
    $collection = config('sentry.data_collection');

    expect($collection['stack_frame_variables']['mode'])->toBe('off')
        ->and($collection['url_query_params']['mode'])->toBe('off')
        ->and($collection['cookies']['mode'])->toBe('off')
        ->and($collection['http_bodies'])->toBe([])
        ->and($collection['user_info'])->toBeFalse();
});
