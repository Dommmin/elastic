<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ExpectsOfferVersion;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Akcja na ofercie bez innych pól (publikacja, wycofanie, anulowanie
 * zgłoszenia ceny, przywrócenie) — niesie tylko wersję z ekranu.
 * Dostęp pilnują middleware tras (`seller.approved` / `can:panel`).
 */
class OfferVersionRequest extends FormRequest
{
    use ExpectsOfferVersion;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return $this->versionRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->versionMessages();
    }
}
