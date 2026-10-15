<?php

namespace App\Http\Requests\Panel;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Odrzucenie zmiany ceny: to, co akceptacja (kwota z kolejki, wersja),
 * plus powód, który zobaczy sprzedawca — ten sam zakres co
 * `ModerationReasonRequest` i value object `Reason`.
 */
class RejectPriceChangeRequest extends PriceChangeDecisionRequest
{
    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'reason.required' => 'Podaj powód.',
            'reason.string' => 'Powód musi być tekstem.',
            'reason.max' => 'Powód może mieć najwyżej 500 znaków.',
        ];
    }
}
