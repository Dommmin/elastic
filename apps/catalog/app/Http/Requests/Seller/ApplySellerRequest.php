<?php

namespace App\Http\Requests\Seller;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Pierwsza linia walidacji zgłoszenia: szybki komunikat pod polem.
 * Te same zakresy pilnują value objecty `SellerName` i `City` w domenie.
 */
class ApplySellerRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'city' => ['required', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Podaj nazwę sklepu.',
            'name.min' => 'Nazwa musi mieć co najmniej 3 znaki.',
            'name.max' => 'Nazwa może mieć najwyżej 120 znaków.',
            'city.required' => 'Podaj miasto.',
            'city.max' => 'Miasto może mieć najwyżej 120 znaków.',
            '*.string' => 'Wartość musi być tekstem.',
        ];
    }
}
