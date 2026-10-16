<?php

namespace App\FakePaymentGateway\Http;

use App\FakePaymentGateway\FakePaymentGateway;
use App\FakePaymentGateway\SessionExpired;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Strona płatności „zewnętrznej” bramki: kwota, termin i przyciski decyzji.
 */
class FakeGatewayController extends Controller
{
    public function show(string $payment, FakePaymentGateway $gateway): Response
    {
        $row = $gateway->find($payment);

        return Inertia::render('fake-gateway/Payment', [
            'payment' => [
                'id' => $row->id,
                'amount_cents' => (int) $row->amount_cents,
                'currency' => $row->currency,
                'status' => $row->status,
                'expires_at' => CarbonImmutable::parse($row->expires_at, 'UTC')->format(DATE_ATOM),
                'expired' => $gateway->isExpired($row),
            ],
            'developerTools' => $gateway->developerToolsEnabled(),
        ]);
    }

    /**
     * DLACZEGO przekierowanie na `return_url` także po odmowie: prawdziwa
     * bramka odsyła kupującego do sklepu niezależnie od wyniku, a o wyniku
     * sklep dowiaduje się z webhooka, nie z przekierowania.
     */
    public function decide(Request $request, string $payment, FakePaymentGateway $gateway): RedirectResponse
    {
        $action = $request->validate([
            'action' => ['required', Rule::in(['pay', 'decline', 'resend', 'pay_expired'])],
        ])['action'];

        $row = $gateway->find($payment);

        try {
            $gateway->decide($payment, $action);
        } catch (SessionExpired $e) {
            return back()->withErrors(['domain' => 'Sesja płatności wygasła — wróć do sklepu i rozpocznij płatność ponownie.']);
        }

        return redirect()->away($row->return_url);
    }
}
