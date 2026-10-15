<?php

namespace App\Http\Requests\Seller;

use App\Http\Requests\Seller\Concerns\NormalizesPrice;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Format ceny sprawdzamy tutaj; próg 30% i limit dobowy rozstrzyga domena.
 */
class UpdateOfferPriceRequest extends FormRequest
{
    use NormalizesPrice;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return ['price' => $this->priceRules()];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->priceMessages();
    }
}
