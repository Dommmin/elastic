<?php

namespace App\Http\Requests\Panel;

use App\Http\Requests\Concerns\ExpectsOfferVersion;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Akceptacja zmiany ceny: moderator decyduje o kwocie, którą widział
 * w kolejce (`expected_price_cents`), na wersji oferty z wiersza.
 * Czy kwota nadal czeka, rozstrzyga agregat (`PendingPriceChangeMismatch`).
 */
class PriceChangeDecisionRequest extends FormRequest
{
    use ExpectsOfferVersion;

    /**
     * Dostęp pilnuje już `can:panel` na grupie tras.
     */
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
            ...$this->versionRules(),
            'expected_price_cents' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->versionMessages(),
            'expected_price_cents.required' => 'Brak zgłoszonej ceny — odśwież kolejkę.',
            'expected_price_cents.integer' => 'Niepoprawna zgłoszona cena — odśwież kolejkę.',
            'expected_price_cents.min' => 'Niepoprawna zgłoszona cena — odśwież kolejkę.',
        ];
    }

    public function expectedPriceCents(): int
    {
        return (int) $this->validated('expected_price_cents');
    }
}
