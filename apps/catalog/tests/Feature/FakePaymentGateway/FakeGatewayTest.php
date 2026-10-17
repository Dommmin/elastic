<?php

use App\FakePaymentGateway\FakePaymentGateway;
use App\FakePaymentGateway\Jobs\SendPaymentWebhookJob;
use App\FakePaymentGateway\SessionExpired;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function fakeGateway(): FakePaymentGateway
{
    return app(FakePaymentGateway::class);
}

/**
 * @return array{payment_id: string, url: string}
 */
function fakeGatewayPayment(?DateTimeImmutable $expiresAt = null, string $reference = 'order-1', int $cents = 4498): array
{
    return fakeGateway()->createPayment($reference, $cents, 'PLN', $expiresAt ?? new DateTimeImmutable('+15 minutes'), 'http://localhost/orders/1');
}

beforeEach(function () {
    config([
        'services.fake_gateway.webhook_secret' => 'test-secret',
        'services.fake_gateway.webhook_url' => 'http://catalog.test/webhooks/payments',
        'services.fake_gateway.webhook_delay_seconds' => 2,
        'services.fake_gateway.fail_first_refund' => false,
    ]);
});

test('createPayment zapisuje sesję i zwraca adres strony bramki', function () {
    $payment = fakeGatewayPayment();

    expect($payment['url'])->toBe(route('fake-gateway.show', $payment['payment_id']))
        ->and(DB::table('fake_gateway_payments')->where('id', $payment['payment_id'])->first())
        ->reference->toBe('order-1')
        ->amount_cents->toBe(4498)
        ->status->toBe('pending')
        ->decided_at->toBeNull();
});

test('strona bramki pokazuje kwotę, odbiorcę i przyciski', function () {
    $payment = fakeGatewayPayment();

    $this->get($payment['url'])
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('fake-gateway/Payment')
            ->where('payment.amount_cents', 4498)
            ->where('payment.currency', 'PLN')
            ->where('payment.expired', false)
            ->where('payment.status', 'pending')
            ->where('developerTools', true));
});

test('decide pay kolejkuje webhook z opóźnieniem i przekierowuje na return_url', function () {
    Queue::fake();
    $payment = fakeGatewayPayment();

    $this->post(route('fake-gateway.decide', $payment['payment_id']), ['action' => 'pay'])
        ->assertRedirect('http://localhost/orders/1');

    expect(DB::table('fake_gateway_payments')->where('id', $payment['payment_id'])->value('status'))->toBe('succeeded');
    Queue::assertPushed(SendPaymentWebhookJob::class, fn ($job) => $job->paymentId === $payment['payment_id'] && $job->delay === 2);
});

test('decide decline oznacza płatność jako nieudaną i też wysyła webhook', function () {
    Queue::fake();
    $payment = fakeGatewayPayment();

    fakeGateway()->decide($payment['payment_id'], 'decline');

    expect(DB::table('fake_gateway_payments')->where('id', $payment['payment_id'])->value('status'))->toBe('failed');
    expect(json_decode(fakeGateway()->signedWebhook($payment['payment_id'])['body'], true))
        ->payment_id->toBe($payment['payment_id'])
        ->reference->toBe('order-1')
        ->status->toBe('failed')
        ->amount_cents->toBe(4498)
        ->currency->toBe('PLN');
    Queue::assertPushed(SendPaymentWebhookJob::class);
});

test('signedWebhook podpisuje surowe body HMAC-SHA256', function () {
    Queue::fake();
    $payment = fakeGatewayPayment();
    fakeGateway()->decide($payment['payment_id'], 'pay');

    $signed = fakeGateway()->signedWebhook($payment['payment_id']);

    expect($signed['signature'])->toBe(hash_hmac('sha256', $signed['body'], 'test-secret'))
        ->and(json_decode($signed['body'], true))->toHaveKeys(['payment_id', 'reference', 'status', 'amount_cents', 'currency', 'occurred_at'])
        ->and(json_decode($signed['body'], true)['status'])->toBe('succeeded');
});

test('bramka nie przyjmie wpłaty po wygaśnięciu sesji', function () {
    Queue::fake();
    $payment = fakeGatewayPayment(new DateTimeImmutable('+15 minutes'));

    $this->travel(16)->minutes();

    expect(fn () => fakeGateway()->decide($payment['payment_id'], 'pay'))->toThrow(SessionExpired::class);
    expect(DB::table('fake_gateway_payments')->where('id', $payment['payment_id'])->value('status'))->toBe('pending');

    $this->get($payment['url'])
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('payment.expired', true));
    Queue::assertNothingPushed();
});

test('wygaśnięcie z formularza wraca na stronę bramki z komunikatem, a nie 500', function () {
    $payment = fakeGatewayPayment(new DateTimeImmutable('+15 minutes'));
    $this->travel(16)->minutes();

    $this->from($payment['url'])
        ->post(route('fake-gateway.decide', $payment['payment_id']), ['action' => 'pay'])
        ->assertRedirect($payment['url'])
        ->assertSessionHasErrors('domain');
});

test('„zapłać mimo wygaśnięcia” daje wpłatę po terminie', function () {
    Queue::fake();
    $payment = fakeGatewayPayment(new DateTimeImmutable('+15 minutes'));
    $this->travel(16)->minutes();

    fakeGateway()->decide($payment['payment_id'], 'pay_expired');

    expect(DB::table('fake_gateway_payments')->where('id', $payment['payment_id'])->value('status'))->toBe('succeeded');
    Queue::assertPushed(SendPaymentWebhookJob::class);
});

test('resend wysyła webhook jeszcze raz', function () {
    Queue::fake();
    $payment = fakeGatewayPayment();
    fakeGateway()->decide($payment['payment_id'], 'pay');

    fakeGateway()->decide($payment['payment_id'], 'resend');

    Queue::assertPushed(SendPaymentWebhookJob::class, 2);
});

test('przyciski deweloperskie zwracają 404 poza local/testing', function (string $action) {
    Queue::fake();
    $payment = fakeGatewayPayment();
    app()->detectEnvironment(fn () => 'production');
    // W produkcji CSRF działa także w testach; sprawdzamy tu 404 akcji, nie token.
    $this->withoutMiddleware(PreventRequestForgery::class);

    $this->post(route('fake-gateway.decide', $payment['payment_id']), ['action' => $action])->assertNotFound();
    $this->get($payment['url'])->assertInertia(fn ($page) => $page->where('developerTools', false));

    Queue::assertNothingPushed();
})->with(['resend', 'pay_expired']);

test('nieznana akcja to 422', function () {
    $payment = fakeGatewayPayment();

    $this->post(route('fake-gateway.decide', $payment['payment_id']), ['action' => 'explode'])->assertSessionHasErrors('action');
});

test('zwrot z tym samym kluczem jest wykonywany raz', function () {
    $payment = fakeGatewayPayment();

    fakeGateway()->refund($payment['payment_id'], 4498, 'refund-1-1');
    fakeGateway()->refund($payment['payment_id'], 4498, 'refund-1-1');

    $rows = DB::table('fake_gateway_refunds')->where('idempotency_key', 'refund-1-1')->get();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->status)->toBe('succeeded')
        ->and($rows[0]->attempts)->toBe(1);
});

test('fail_first_refund: pierwsza próba rzuca, druga przechodzi', function () {
    config(['services.fake_gateway.fail_first_refund' => true]);
    $payment = fakeGatewayPayment();

    expect(fn () => fakeGateway()->refund($payment['payment_id'], 4498, 'refund-1-1'))->toThrow(RuntimeException::class);
    fakeGateway()->refund($payment['payment_id'], 4498, 'refund-1-1');

    // Każdy klucz ma własną „pierwszą próbę”.
    expect(fn () => fakeGateway()->refund($payment['payment_id'], 100, 'refund-1-2'))->toThrow(RuntimeException::class);

    $row = DB::table('fake_gateway_refunds')->where('idempotency_key', 'refund-1-1')->first();
    expect($row->status)->toBe('succeeded')->and($row->attempts)->toBe(2);
});

test('job wysyła podpisany webhook na skonfigurowany adres', function () {
    Queue::fake();
    Http::fake(['catalog.test/*' => Http::response('', 200)]);
    $payment = fakeGatewayPayment();
    fakeGateway()->decide($payment['payment_id'], 'pay');

    (new SendPaymentWebhookJob($payment['payment_id']))->handle(fakeGateway());

    Http::assertSent(function ($request) use ($payment) {
        $signed = fakeGateway()->signedWebhook($payment['payment_id']);

        return $request->url() === 'http://catalog.test/webhooks/payments'
            && $request->body() === $signed['body']
            && $request->header('X-Signature') === [$signed['signature']];
    });
});

test('job zwalnia się z backoffem, gdy odbiorca nie odpowiada 2xx', function () {
    Queue::fake();
    Http::fake(['catalog.test/*' => Http::response('boom', 500)]);
    $payment = fakeGatewayPayment();
    fakeGateway()->decide($payment['payment_id'], 'pay');

    $job = (new SendPaymentWebhookJob($payment['payment_id']))->withFakeQueueInteractions();

    $job->handle(fakeGateway());

    // Pierwsza próba → pierwszy element backoffu.
    $job->assertReleased(delay: 2);
    expect($job->tries)->toBe(10)->and($job->backoff)->toBe([2, 10, 30]);
});
