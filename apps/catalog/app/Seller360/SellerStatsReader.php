<?php

namespace App\Seller360;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Throwable;

/**
 * Widok „Twoje statystyki” sprzedawcy (ETAP 13). Czyta WYŁĄCZNIE `seller-360-public/_doc/<id>`
 * klientem `es.seller_view` (konto `catalog_seller_view`, rola `seller_stats_view`: read tylko na tym aliasie).
 *
 * Izolacja nie opiera się na FLS/DLS (licencja basic ich nie ma): alias publiczny nie zawiera `billing`
 * ani `complaints`, a konto nie widzi `seller-360`. Czytnik dokłada drugą warstwę: biała lista kluczy,
 * więc nawet źle zasilony alias nie wypuści `nip`, `region`, `synthetic` ani sekcji spoza widoku.
 * Id zawsze z wywołującego (kontroler bierze je z zalogowanego sprzedawcy), nigdy z żądania.
 */
class SellerStatsReader
{
    public const ALIAS = 'seller-360-public';

    public function __construct(private readonly Client $client) {}

    /**
     * Zwraca null, gdy sprzedawca nie ma dokumentu (404) albo ES jest nieosiągalny: strona ma działać dalej.
     * Błąd transportu idzie do `report()`, 404 to zwykły stan.
     *
     * @return array{profile: array<string, mixed>|null, offers: array<string, mixed>|null, sales: array<string, mixed>|null, courier_on_time_rate: float|null, freshness: array<string, string|null>}|null
     */
    public function forSeller(int $sellerId): ?array
    {
        try {
            $source = $this->client->get(['index' => self::ALIAS, 'id' => (string) $sellerId])->asArray()['_source'] ?? [];
        } catch (ClientResponseException $e) {
            if ($e->getCode() === 404) {
                return null;
            }
            report($e);

            return null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        $courier = $source['registry']['courier'] ?? null;

        return [
            'profile' => $this->section($source['profile'] ?? null),
            'offers' => $this->section($source['offers'] ?? null),
            'sales' => $this->section($source['sales'] ?? null),
            'courier_on_time_rate' => isset($courier['on_time_rate']) ? (float) $courier['on_time_rate'] : null,
            'freshness' => [
                'profile' => $source['profile']['_meta']['updated_at'] ?? null,
                'offers' => $source['offers']['_meta']['updated_at'] ?? null,
                'sales' => $source['sales']['_meta']['updated_at'] ?? null,
                'courier' => $source['registry']['_meta']['updated_at'] ?? null,
            ],
        ];
    }

    /**
     * @param  mixed  $section
     * @return array<string, mixed>|null sekcja bez `_meta` (z niej do widoku idzie tylko `updated_at`, w `freshness`)
     */
    private function section(mixed $section): ?array
    {
        if (! is_array($section)) {
            return null;
        }
        unset($section['_meta']);

        return $section;
    }
}
