<?php

namespace App\Http\Requests\Shop;

use App\Models\CartItem;
use App\Models\Offer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Dodawanie do koszyka: ilość (istniejąca + nowa) nie może przekroczyć
 * min(10, dostępne), oferta ma być widoczna i cudza. Odmowa to błąd pola,
 * a koszyk zostaje bez zmian (Review Focus 2).
 *
 * DLACZEGO sumujemy z tym, co już jest w koszyku: ta sama oferta dodana
 * dwa razy to jedna pozycja, a `LineQuantity` w domenie przyjmie najwyżej 10.
 */
class AddToCartRequest extends FormRequest
{
    public const int MAX_PER_LINE = 10;

    private ?Offer $offer = null;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'offer_id' => ['required', 'integer', 'exists:offers,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.self::MAX_PER_LINE],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'offer_id.required' => 'Wybierz ofertę.',
            'offer_id.integer' => 'Wybierz ofertę z listy.',
            'offer_id.exists' => 'Ta oferta już nie istnieje.',
            'quantity.required' => 'Podaj ilość.',
            'quantity.integer' => 'Ilość musi być liczbą całkowitą.',
            'quantity.min' => 'Ilość musi wynosić co najmniej 1.',
            'quantity.max' => 'Możesz kupić najwyżej '.self::MAX_PER_LINE.' sztuk jednej oferty.',
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $offer = Offer::query()->withAvailable()->listed()->with('seller:id,user_id')->find($this->integer('offer_id'));

            if ($offer === null) {
                $validator->errors()->add('offer_id', 'Ta oferta nie jest już dostępna.');

                return;
            }

            if ($offer->seller->user_id === $this->user()->getAuthIdentifier()) {
                $validator->errors()->add('offer_id', 'Nie możesz kupić własnej oferty');

                return;
            }

            $inCart = (int) CartItem::query()
                ->where('user_id', $this->user()->getAuthIdentifier())
                ->where('offer_id', $offer->id)
                ->value('quantity');
            $max = self::maxQuantity($offer);

            if ($inCart + $this->integer('quantity') > $max) {
                $validator->errors()->add('quantity', $inCart > 0
                    ? "Masz już w koszyku {$inCart} szt.; możesz mieć najwyżej {$max} szt. tej oferty."
                    : "Możesz dodać najwyżej {$max} szt. tej oferty.");

                return;
            }

            $this->offer = $offer;
        }];
    }

    /**
     * Oferta sprawdzona przez walidację, z kolumną `available`.
     */
    public function offer(): Offer
    {
        return $this->offer;
    }

    public static function maxQuantity(Offer $offer): int
    {
        return max(0, min(self::MAX_PER_LINE, (int) $offer->available));
    }
}
