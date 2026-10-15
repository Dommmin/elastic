<?php

namespace App\Http\Requests\Seller;

use App\Http\Requests\Seller\Concerns\NormalizesPrice;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use SellerPortal\Domain\Offer\Condition;

/**
 * Pierwsza linia walidacji szkicu: komunikat pod polem. Te same zakresy
 * pilnują value objecty (`Money`, `Stock`, `ShippingTime`) w domenie.
 */
class StoreOfferRequest extends FormRequest
{
    use NormalizesPrice;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, ValidationRule|string|object>>
     */
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'price' => $this->priceRules(),
            'stock' => ['required', 'integer', 'min:0', 'max:100000'],
            'condition' => ['required', Rule::enum(Condition::class)],
            'shipping_days' => ['required', 'integer', 'min:1', 'max:30'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->priceMessages(),
            ...OfferFieldMessages::STOCK,
            ...OfferFieldMessages::DETAILS,
            'product_id.required' => 'Wybierz produkt.',
            'product_id.integer' => 'Wybierz produkt z listy.',
            'product_id.exists' => 'Nie znaleziono produktu w katalogu.',
        ];
    }
}
