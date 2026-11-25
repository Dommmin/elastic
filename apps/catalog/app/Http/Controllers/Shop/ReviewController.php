<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shop\StoreReviewRequest;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use App\Security\ClientFingerprint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Recenzja ze sklepu (ETAP 15). Zdarzenie `review.created` niesie odcisk klienta
 * (hasz i prefiks IP, User-Agent, wiek konta) dla detekcji nadużyć; surowe IP
 * nie opuszcza tej metody. Pola są tylko w payloadzie outboxu, bez nowych kolumn.
 */
class ReviewController extends Controller
{
    public function store(StoreReviewRequest $request, Product $product, ClientFingerprint $fingerprint): RedirectResponse
    {
        $user = $request->user();
        $ip = (string) $request->ip();

        // `mb_scrub` zamienia niepoprawne bajty UTF-8 na `?`, żeby json_encode payloadu outboxu nie padł.
        $userAgent = ClientFingerprint::userAgent(
            ($ua = $request->userAgent()) === null ? null : mb_scrub($ua, 'UTF-8'),
        );

        // FormRequest sprawdził duplikat szybką ścieżką, ale dwa równoległe żądania mogą oba ją przejść.
        // Blokada wiersza użytkownika szereguje je, a ponowne sprawdzenie pod blokadą zamyka wyścig
        // bez unikalnego indeksu (dane z seedera mogą mieć duplikaty).
        DB::transaction(function () use ($request, $product, $user, $fingerprint, $ip, $userAgent): void {
            User::query()->whereKey($user->getAuthIdentifier())->lockForUpdate()->first();

            $exists = Review::query()
                ->where('product_id', $product->id)
                ->where('user_id', $user->getAuthIdentifier())
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages(['rating' => StoreReviewRequest::DUPLICATE_MESSAGE]);
            }

            Review::createWithOutbox(
                [
                    'product_id' => $product->id,
                    'user_id' => $user->getAuthIdentifier(),
                    'rating' => $request->integer('rating'),
                    'body' => $request->input('body'),
                ],
                'review.created',
                fn (Review $review): array => $review->attributesToArray() + [
                    'client_ip_hash' => $fingerprint->hash($ip),
                    'client_ip_prefix' => $fingerprint->prefix($ip),
                    'client_user_agent' => $userAgent,
                    'user_account_age_hours' => (int) $user->created_at->diffInHours(now(), true),
                ],
            );
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Dziękujemy za recenzję']);

        return to_route('products.show', $product);
    }
}
