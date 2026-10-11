<?php

namespace App\Http\Requests\Panel;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateOfferRequest extends FormRequest
{
    /**
     * Dostęp pilnuje już `can:panel` na grupie tras.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Polska klawiatura daje „19,99”; `numeric` rozumie tylko kropkę.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('price'))) {
            $this->merge(['price' => str_replace(',', '.', trim($this->input('price')))]);
        }
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'price' => ['required', 'numeric', 'gt:0', 'lte:1000000'],
            'stock' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * Cena w formularzu jest w zł, w bazie w groszach.
     *
     * @return array{price_cents: int, stock: int}
     */
    public function attributesForUpdate(): array
    {
        return [
            'price_cents' => (int) round((float) $this->validated('price') * 100),
            'stock' => (int) $this->validated('stock'),
        ];
    }
}
