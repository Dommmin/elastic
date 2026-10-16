<?php

namespace App\Http\Requests\Shop;

use App\Models\Offer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Zmiana pozycji koszyka: nowa ilość (w granicach min(10, dostępne), o ile
 * oferta jest jeszcze widoczna) i/lub zaakceptowanie nowej ceny.
 */
class UpdateCartItemRequest extends FormRequest
{
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
            'quantity' => ['required_without:accept_price', 'integer', 'min:1', 'max:'.AddToCartRequest::MAX_PER_LINE],
            'accept_price' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quantity.required_without' => 'Podaj ilość.',
            'quantity.integer' => 'Ilość musi być liczbą całkowitą.',
            'quantity.min' => 'Ilość musi wynosić co najmniej 1.',
            'quantity.max' => 'Możesz kupić najwyżej '.AddToCartRequest::MAX_PER_LINE.' sztuk jednej oferty.',
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->has('quantity')) {
                return;
            }

            $offer = Offer::query()->withAvailable()->listed()->find((int) $this->route('offer'));

            if ($offer !== null && $this->integer('quantity') > AddToCartRequest::maxQuantity($offer)) {
                $max = AddToCartRequest::maxQuantity($offer);
                $validator->errors()->add('quantity', "Możesz mieć najwyżej {$max} szt. tej oferty.");
            }
        }];
    }
}
