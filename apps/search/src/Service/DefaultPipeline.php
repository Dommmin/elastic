<?php

namespace App\Service;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;

/**
 * `index.default_pipeline` z pliku mapowania (ETAP 12: products-v6 →
 * `products-enrich-popularity`). Indeks z pipeline'em, którego nie ma, ES
 * utworzy bez słowa, ale potem odrzuci KAŻDY zapis (400): `_reindex`,
 * strumień, backfill. Dlatego `search:index:create` i `migrate prepare`
 * sprawdzają pipeline PRZED utworzeniem indeksu i odmawiają.
 */
final class DefaultPipeline
{
    public const SETUP_HINT = 'Uruchom najpierw `make analytics-setup` (tworzy pipeline i politykę enrich), potem powtórz.';

    /**
     * Nazwa pipeline'u z `settings.index.default_pipeline`, płaskiego
     * `settings.default_pipeline` albo `settings["index.default_pipeline"]`;
     * null, gdy brak albo `_none` (jawne „bez pipeline'u”).
     *
     * @param  array<string, mixed>  $body
     */
    public static function of(array $body): ?string
    {
        $settings = $body['settings'] ?? [];
        $name = $settings['index']['default_pipeline'] ?? $settings['default_pipeline'] ?? $settings['index.default_pipeline'] ?? null;

        return \is_string($name) && $name !== '' && $name !== '_none' ? $name : null;
    }

    /**
     * Nazwa brakującego pipeline'u albo null (brak ustawienia lub pipeline istnieje).
     * Inne błędy niż 404 (np. 403) lecą dalej — lepiej przerwać niż zgadywać.
     *
     * @param  array<string, mixed>  $body
     */
    public static function missing(Client $client, array $body): ?string
    {
        $name = self::of($body);
        if ($name === null) {
            return null;
        }

        try {
            $client->ingest()->getPipeline(['id' => $name]);
        } catch (ClientResponseException $e) {
            if ($e->getCode() === 404) {
                return $name;
            }
            throw $e;
        }

        return null;
    }
}
