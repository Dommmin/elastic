<?php

namespace App\Http\Requests\Shop;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Ordering\Application\Order\CartLine;

/**
 * Pozycje widziane przez kupującego, razem z cenami jednostkowymi (H5).
 * `lines` bywa puste dla pustego koszyka (drugi klik, R9): to rozstrzyga
 * kontroler, nie walidacja.
 */
class CheckoutRequest extends FormRequest
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
            'lines' => ['nullable', 'array'],
            'lines.*.offer_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:'.AddToCartRequest::MAX_PER_LINE],
            'lines.*.unit_price_cents' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * @return list<CartLine>
     */
    public function cartLines(): array
    {
        return array_map(
            static fn (array $line): CartLine => new CartLine((int) $line['offer_id'], (int) $line['quantity'], (int) $line['unit_price_cents']),
            array_values($this->validated('lines') ?? []),
        );
    }
}
