<?php

namespace App\Http\Requests\Seller;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateOfferStockRequest extends FormRequest
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
        return ['stock' => ['required', 'integer', 'min:0', 'max:100000']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return OfferFieldMessages::STOCK;
    }
}
