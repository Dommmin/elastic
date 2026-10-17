<?php

namespace App\Http\Requests\Seller;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * DLACZEGO walidacja tu, a nie tylko w `TrackingNumber`: błąd ma wrócić przy polu,
 * a nie jako ogólny komunikat. Spacje obcina middleware `TrimStrings`, więc
 * numer ze samych spacji jest pusty i pada na `required`.
 */
class ShipOrderRequest extends FormRequest
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
        return ['tracking_number' => ['required', 'string', 'min:3', 'max:64']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tracking_number.required' => 'Podaj numer przesyłki',
            'tracking_number.min' => 'Numer przesyłki ma mieć od 3 do 64 znaków',
            'tracking_number.max' => 'Numer przesyłki ma mieć od 3 do 64 znaków',
        ];
    }
}
