<?php

namespace App\Http\Requests\Seller;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use SellerPortal\Domain\Seller\TaxId;
use SharedKernel\InvalidValue;

/**
 * Pierwsza linia walidacji zgłoszenia: szybki komunikat pod polem.
 * Te same zakresy pilnują value objecty `SellerName`, `City` i `TaxId` w domenie.
 */
class ApplySellerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * DLACZEGO normalizacja przed walidacją: użytkownik wpisuje `526-104-08-28`,
     * a unikalność sprawdzamy na postaci z samych cyfr, tak jak w bazie.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('nip'))) {
            $this->merge(['nip' => str_replace(['-', ' '], '', $this->input('nip'))]);
        }
    }

    /**
     * @return array<string, array<int, ValidationRule|string|Closure>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'city' => ['required', 'string', 'max:120'],
            'nip' => ['required', 'string', $this->validTaxId(...), 'unique:sellers,nip'],
        ];
    }

    /** Poprawność NIP-u (10 cyfr, suma kontrolna) pilnuje ten sam value object co w domenie. */
    private function validTaxId(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            TaxId::fromString((string) $value);
        } catch (InvalidValue $e) {
            $fail($e->getMessage());
        }
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
            'nip.required' => 'Podaj NIP.',
            'nip.unique' => 'Ten NIP jest już zarejestrowany.',
            '*.string' => 'Wartość musi być tekstem.',
        ];
    }
}
