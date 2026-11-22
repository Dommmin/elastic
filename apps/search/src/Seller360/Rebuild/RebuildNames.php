<?php

namespace App\Seller360\Rebuild;

/**
 * Nazwy, na których działa przebudowa: aliasy odczytu (zapis to `<alias>-write`), szablony indeksów,
 * indeks ocen kurierów i polityka enrich czytająca alias główny. Domyślnie żywe `seller-360`;
 * test podaje własną przestrzeń nazw, więc nigdy nie dotyka żywych aliasów.
 * Nowy indeks to `<alias>-v<N+1>`, gdzie N to największy numer wśród `<alias>-v*` obu rodzin.
 */
final readonly class RebuildNames
{
    public function __construct(
        public string $mainAlias = 'seller-360',
        public string $publicAlias = 'seller-360-public',
        public string $mainTemplate = 'seller-360',
        public string $publicTemplate = 'seller-360-public',
        public string $ratingsIndex = 'courier-ratings',
        public ?string $enrichPolicy = 'seller-by-nip',
    ) {
    }

    public function mainWriteAlias(): string
    {
        return $this->mainAlias.'-write';
    }

    public function publicWriteAlias(): string
    {
        return $this->publicAlias.'-write';
    }
}
