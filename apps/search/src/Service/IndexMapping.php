<?php

namespace App\Service;

/**
 * Pliki mapowań `products-vN.json` z repo (mapowania są kodem,
 * docs/01-INFRASTRUKTURA.md, "Konwencje"). Jedno miejsce, z którego czytają
 * `search:index:create` i `search:index:migrate prepare`.
 */
final class IndexMapping
{
    /**
     * ŚCIEŻKA ABSOLUTNA, nie wędrówka `../../` od `%kernel.project_dir%`.
     * Kontener search-consumer widzi WYŁĄCZNIE `./apps/search` jako `/app` —
     * katalog `infra/` z korzenia repo w ogóle nie istnieje w jego systemie
     * plików. compose.yaml montuje `infra/elasticsearch/mappings` osobno,
     * dokładnie pod tę ścieżkę (patrz komentarz przy `search-consumer.volumes`).
     * Odkryte dopiero przy realnym uruchomieniu — na hosta `../../` "działało"
     * tylko dlatego, że lokalny filesystem ma tam repo, czego kontener nie ma.
     */
    public const DIR = '/infra/elasticsearch/mappings';

    /** @param string $dir parametr dla testów (tymczasowy katalog z kolejnymi wersjami) */
    public function __construct(private readonly string $dir = self::DIR)
    {
    }

    public function path(int $version): string
    {
        return $this->dir.'/products-v'.$version.'.json';
    }

    public function exists(int $version): bool
    {
        return is_file($this->path($version));
    }

    /**
     * Treść gotowa do `indices()->create`: `settings` + `mappings`.
     * Górne `_meta` pliku to dokumentacja dla ludzi — ES przyjąłby ją jako
     * część mappings, ale nie ma tu po co jej wysyłać.
     *
     * @return array<string, mixed>
     */
    public function load(int $version): array
    {
        if (! $this->exists($version)) {
            throw new \RuntimeException('Brak pliku mapowania: '.$this->path($version));
        }

        $body = json_decode(file_get_contents($this->path($version)), associative: true, flags: \JSON_THROW_ON_ERROR);
        unset($body['_meta']);

        return $body;
    }
}
