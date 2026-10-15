<?php

namespace App\Http\Requests\Panel;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Notatka do zawieszenia oferty — sprzedawca widzi ją przy ofercie, więc
 * moderator musi ją podać. Ten sam zakres pilnuje value object `Reason`.
 */
class SuspendOfferRequest extends FormRequest
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
            'note' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'note.required' => 'Podaj powód zawieszenia.',
            'note.string' => 'Powód musi być tekstem.',
            'note.max' => 'Powód może mieć najwyżej 500 znaków.',
        ];
    }
}
