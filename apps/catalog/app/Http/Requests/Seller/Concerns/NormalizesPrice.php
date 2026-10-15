<?php

namespace App\Http\Requests\Seller\Concerns;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Cena wpisywana przez człowieka w złotych → grosze.
 *
 * DLACZEGO normalizacja przed walidacją: polska klawiatura i `Intl` dają
 * „1 299,99” (spacja, także twarda, i przecinek), a reguła `numeric` rozumie
 * tylko „1299.99”. Zachowanie takie jak w dawnym panelowym
 * `UpdateOfferRequest`, plus jawny zakaz trzeciego miejsca po przecinku —
 * „12,999” to raczej literówka niż 13 zł.
 */
trait NormalizesPrice
{
    protected function prepareForValidation(): void
    {
        $price = $this->input('price');

        if (is_string($price) || is_int($price) || is_float($price)) {
            $normalized = preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', (string) $price) ?? (string) $price;
            $this->merge(['price' => str_replace(',', '.', $normalized)]);
        }
    }

    /**
     * @return array<int, ValidationRule|string>
     */
    protected function priceRules(): array
    {
        return ['bail', 'required', 'string', 'regex:/^\d+(\.\d{1,2})?$/', 'numeric', 'gt:0', 'lte:1000000'];
    }

    /**
     * @return array<string, string>
     */
    protected function priceMessages(): array
    {
        return [
            'price.required' => 'Podaj cenę.',
            'price.string' => 'Podaj cenę w złotych, np. 1299,99.',
            'price.regex' => 'Podaj cenę w złotych, np. 1299,99 (najwyżej 2 miejsca po przecinku).',
            'price.numeric' => 'Podaj cenę w złotych, np. 1299,99.',
            'price.gt' => 'Cena musi być większa od zera.',
            'price.lte' => 'Cena może wynosić najwyżej 1 000 000 zł.',
        ];
    }

    public function priceCents(): int
    {
        return (int) round((float) $this->validated('price') * 100);
    }
}
