<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Wersja oferty, którą formularz wyrenderował — handler porówna ją
 * z wersją wczytaną teraz (`SellerPortal\Application\ExpectedVersion`).
 *
 * DLACZEGO wymagana, a nie opcjonalna: formularz bez wersji to dokładnie
 * ten przypadek, przed którym chronimy (cichy zapis na nieaktualnej stronie).
 * Komunikat mówi „odśwież”, bo brak wersji oznacza stary lub zepsuty widok.
 */
trait ExpectsOfferVersion
{
    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    protected function versionRules(): array
    {
        return ['version' => ['required', 'integer', 'min:1']];
    }

    /**
     * @return array<string, string>
     */
    protected function versionMessages(): array
    {
        return [
            'version.required' => 'Brak wersji oferty — odśwież stronę.',
            'version.integer' => 'Niepoprawna wersja oferty — odśwież stronę.',
            'version.min' => 'Niepoprawna wersja oferty — odśwież stronę.',
        ];
    }

    public function expectedVersion(): int
    {
        return (int) $this->validated('version');
    }
}
