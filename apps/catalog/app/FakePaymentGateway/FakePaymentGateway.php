<?php

namespace App\FakePaymentGateway;

use App\FakePaymentGateway\Jobs\SendPaymentWebhookJob;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Udawany zewnętrzny dostawca płatności (styl Przelewy24 / Stripe Checkout).
 *
 * DLACZEGO poza Orderingiem: to osobny system z własnymi tabelami i trasami.
 * Ordering zna go tylko przez port `PaymentGateway` i podpisany webhook,
 * dokładnie jak znałby prawdziwego dostawcę.
 */
class FakePaymentGateway
{
    private const array ACTIONS = ['pay', 'decline', 'resend', 'pay_expired'];

    /** Akcje deweloperskie: w produkcji bramka nie ma takich przycisków. */
    private const array DEVELOPER_ACTIONS = ['resend', 'pay_expired'];

    /** @return array{payment_id: string, url: string} */
    public function createPayment(string $reference, int $amountCents, string $currency, DateTimeImmutable $expiresAt, string $returnUrl): array
    {
        $id = (string) Str::ulid();

        DB::table('fake_gateway_payments')->insert([
            'id' => $id,
            'reference' => $reference,
            'amount_cents' => $amountCents,
            'currency' => $currency,
            'status' => 'pending',
            'expires_at' => $expiresAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
            'return_url' => $returnUrl,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['payment_id' => $id, 'url' => route('fake-gateway.show', $id)];
    }

    public function find(string $paymentId): stdClass
    {
        return DB::table('fake_gateway_payments')->where('id', $paymentId)->first()
            ?? throw new NotFoundHttpException;
    }

    public function developerToolsEnabled(): bool
    {
        return app()->environment(['local', 'testing']);
    }

    /**
     * @throws SessionExpired gdy `pay` po terminie sesji
     * @throws NotFoundHttpException dla akcji deweloperskich poza local/testing
     */
    public function decide(string $paymentId, string $action): void
    {
        if (! in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException("Nieznana akcja bramki: {$action}");
        }

        if (in_array($action, self::DEVELOPER_ACTIONS, true) && ! $this->developerToolsEnabled()) {
            throw new NotFoundHttpException;
        }

        $payment = $this->find($paymentId);

        if ($action === 'resend') {
            if ($payment->decided_at === null) {
                throw new ConflictHttpException('Nie ma czego wysyłać ponownie: płatność czeka na decyzję.');
            }
            $this->queueWebhook($paymentId);

            return;
        }

        if ($action === 'pay' && $this->isExpired($payment)) {
            throw new SessionExpired;
        }

        // Rozstrzygnięta sesja nie przyjmuje drugiej decyzji; ponowne kliknięcie
        // (dwuklik) nie może zamienić „zapłacone” na „odmówione”.
        if ($payment->decided_at !== null) {
            return;
        }

        DB::table('fake_gateway_payments')->where('id', $paymentId)->update([
            'status' => $action === 'decline' ? 'failed' : 'succeeded',
            'decided_at' => now('UTC')->format('Y-m-d H:i:s.u'),
            'updated_at' => now(),
        ]);

        $this->queueWebhook($paymentId);
    }

    public function isExpired(stdClass $payment): bool
    {
        return now()->greaterThan(CarbonImmutable::parse($payment->expires_at, 'UTC'));
    }

    /**
     * Ten sam klucz → ten sam wynik: drugie wywołanie niczego nie zmienia.
     *
     * @throws RuntimeException gdy `fail_first_refund` i to pierwsza próba tego klucza
     */
    public function refund(string $paymentId, int $amountCents, string $idempotencyKey): void
    {
        DB::transaction(function () use ($paymentId, $amountCents, $idempotencyKey): void {
            DB::table('fake_gateway_refunds')->insertOrIgnore([
                'idempotency_key' => $idempotencyKey,
                'payment_id' => $paymentId,
                'amount_cents' => $amountCents,
                'status' => 'pending',
                'attempts' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $refund = DB::table('fake_gateway_refunds')->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();

            if ($refund->status === 'succeeded') {
                return;
            }

            DB::table('fake_gateway_refunds')->where('idempotency_key', $idempotencyKey)->update([
                'attempts' => $refund->attempts + 1,
                'updated_at' => now(),
            ]);
        });

        $refund = DB::table('fake_gateway_refunds')->where('idempotency_key', $idempotencyKey)->first();

        if ($refund->status === 'succeeded') {
            return;
        }

        // Awaria pokazuje ponowienia zwrotu w sadze; stan `attempts` przeżywa,
        // bo wyjątek leci już po zatwierdzeniu transakcji.
        if (config('services.fake_gateway.fail_first_refund') && $refund->attempts === 1) {
            throw new RuntimeException('Bramka płatności chwilowo nie działa (symulowana awaria zwrotu).');
        }

        DB::table('fake_gateway_refunds')->where('idempotency_key', $idempotencyKey)->update([
            'status' => 'succeeded',
            'updated_at' => now(),
        ]);
    }

    /**
     * Surowe body i jego podpis HMAC-SHA256. Podpis liczymy z tych samych
     * bajtów, które wysyłamy: odbiorca weryfikuje surowe body, nie JSON po parsowaniu.
     *
     * @return array{body: string, signature: string}
     */
    public function signedWebhook(string $paymentId): array
    {
        $payment = $this->find($paymentId);

        $body = json_encode([
            'payment_id' => $payment->id,
            'reference' => $payment->reference,
            'status' => $payment->status,
            'amount_cents' => (int) $payment->amount_cents,
            'currency' => $payment->currency,
            'occurred_at' => CarbonImmutable::parse((string) ($payment->decided_at ?? $payment->updated_at), 'UTC')->format(DATE_ATOM),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return [
            'body' => $body,
            'signature' => hash_hmac('sha256', $body, (string) config('services.fake_gateway.webhook_secret')),
        ];
    }

    private function queueWebhook(string $paymentId): void
    {
        SendPaymentWebhookJob::dispatch($paymentId)
            ->onConnection('database')
            ->delay((int) config('services.fake_gateway.webhook_delay_seconds'));
    }
}
