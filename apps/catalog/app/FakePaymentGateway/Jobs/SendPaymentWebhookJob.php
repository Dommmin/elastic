<?php

namespace App\FakePaymentGateway\Jobs;

use App\FakePaymentGateway\FakePaymentGateway;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Wysyła podpisany webhook o decyzji kupującego.
 *
 * DLACZEGO ponawiamy sami (`release`), a nie przez wyjątek: prawdziwa bramka
 * powtarza dostarczenie, dopóki nie dostanie 2xx, i robi to z rosnącą przerwą.
 * Odbiorca bywa chwilowo niedostępny (restart kontenera), więc błąd sieci
 * liczymy jak odpowiedź ≠ 2xx.
 */
final class SendPaymentWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    /** @var list<int> */
    public array $backoff = [2, 10, 30];

    public function __construct(public readonly string $paymentId) {}

    public function handle(FakePaymentGateway $gateway): void
    {
        $signed = $gateway->signedWebhook($this->paymentId);

        try {
            $delivered = Http::withBody($signed['body'], 'application/json')
                ->withHeaders(['X-Signature' => $signed['signature']])
                ->post((string) config('services.fake_gateway.webhook_url'))
                ->successful();
        } catch (ConnectionException) {
            $delivered = false;
        }

        if (! $delivered) {
            $this->release($this->backoff[min($this->attempts(), count($this->backoff)) - 1]);
        }
    }
}
