<?php

namespace App\Http\Requests\Panel;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Powód decyzji moderatora o sprzedawcy (odrzucenie zgłoszenia, blokada;
 * odrzucenie zmiany ceny ma `RejectPriceChangeRequest`) — trafia do sprzedawcy, więc nie może być pusty. Ten sam
 * zakres pilnuje value object `Reason`.
 */
class ModerationReasonRequest extends FormRequest
{
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
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Podaj powód.',
            'reason.string' => 'Powód musi być tekstem.',
            'reason.max' => 'Powód może mieć najwyżej 500 znaków.',
        ];
    }
}
