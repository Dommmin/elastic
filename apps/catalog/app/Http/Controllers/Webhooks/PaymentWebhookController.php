<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Ordering\Application\Order\RecordPayment;
use Ordering\Application\Order\RecordPaymentFailure;
use Ordering\Application\Order\RecordPaymentFailureHandler;
use Ordering\Application\Order\RecordPaymentHandler;
use Ordering\Domain\OrderNotFound;
use SharedKernel\Currency;

/**
 * ACL między bramką płatności a Orderingiem: sprawdza podpis, tłumaczy
 * payload dostawcy na komendy domeny. Wymiana dostawcy to nowy kontroler.
 *
 * DLACZEGO prawie wszystko kończy się 200: bramka ponawia dostarczenie, aż
 * dostanie 2xx. Wiadomość, której nie da się przetworzyć (nieznane
 * zamówienie, zepsuty payload), nie stanie się lepsza po ponowieniu, więc
 * zostawiamy ostrzeżenie w logu i potwierdzamy odbiór. Tylko zły podpis daje 401.
 *
 * DLACZEGO pusty sekret to 500, a nie „sprawdź podpis jak zwykle”: HMAC
 * z pustym kluczem policzy każdy, więc brak konfiguracji otwierałby
 * księgowanie wpłat dla każdego. Zamykamy się (fail closed), a 5xx sprawia,
 * że bramka ponowi dostarczenie, gdy sekret wróci do konfiguracji.
 */
class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, RecordPaymentHandler $recordPayment, RecordPaymentFailureHandler $recordFailure): Response
    {
        $secret = (string) config('services.fake_gateway.webhook_secret');

        if ($secret === '') {
            Log::error('Webhook płatności odrzucony: brak sekretu FAKE_GATEWAY_WEBHOOK_SECRET w konfiguracji.');

            return response('Webhook nie jest skonfigurowany.', 500);
        }

        $body = $request->getContent();
        $expected = hash_hmac('sha256', $body, $secret);

        if (! hash_equals($expected, (string) $request->header('X-Signature'))) {
            return response('Nieprawidłowy podpis.', 401);
        }

        $payload = json_decode($body, true);
        $validator = Validator::make(is_array($payload) ? $payload : [], [
            'payment_id' => ['required', 'string', 'min:1', 'max:64'],
            'reference' => ['required', 'string', 'regex:/^order-[1-9][0-9]{0,17}$/'],
            'status' => ['required', Rule::in(['succeeded', 'failed'])],
            // Wpłata 0 gr to nie wpłata: bez `min:1` skończyłaby się zwrotem zera.
            'amount_cents' => ['required', 'integer', 'min:1'],
            'currency' => ['required', Rule::enum(Currency::class)],
            'occurred_at' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            Log::warning('Webhook płatności z nieprawidłowym payloadem — pominięty.', ['errors' => $validator->errors()->all()]);

            return response('', 200);
        }

        $data = $validator->validated();
        $orderId = (int) substr($data['reference'], strlen('order-'));

        try {
            if ($data['status'] === 'succeeded') {
                $recordPayment->handle(new RecordPayment($orderId, $data['payment_id'], (int) $data['amount_cents'], $data['currency']));
            } else {
                $recordFailure->handle(new RecordPaymentFailure($orderId, $data['payment_id']));
            }
        } catch (OrderNotFound) {
            Log::warning('Webhook płatności dla nieznanego zamówienia — pominięty.', ['reference' => $data['reference'], 'payment_id' => $data['payment_id']]);
        }

        return response('', 200);
    }
}
