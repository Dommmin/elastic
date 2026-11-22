<?php

namespace App\Seller360\Registry;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Klient zewnętrznego rejestru firm i kurierów (w repo: `tools/registry-mock`).
 *
 * Timeout 2 s na żądanie, do 2 ponowień z backoffem 200 i 800 ms (razem 3 próby). Każda niepowodzenie
 * po ostatniej próbie to `RegistryUnavailable`; decyzję "co dalej" (cache, stale) podejmuje RegistrySync.
 * Bez `final`: RegistrySync testujemy z dublerem tej klasy.
 */
class RegistryClient
{
    public const TIMEOUT_SECONDS = 2.0;

    /** Pauzy przed kolejnymi próbami, w ms. */
    private const BACKOFF_MS = [200, 800];

    private readonly HttpClientInterface $http;

    /** @var \Closure(int): void */
    private readonly \Closure $sleepMs;

    /**
     * @param  (\Closure(int): void)|null  $sleepMs  uśpienie w ms (podmieniane w testach)
     */
    public function __construct(
        private readonly string $registryBaseUrl,
        ?HttpClientInterface $http = null,
        ?\Closure $sleepMs = null,
    ) {
        $this->http = ($http ?? HttpClient::create())->withOptions(['timeout' => self::TIMEOUT_SECONDS, 'max_duration' => self::TIMEOUT_SECONDS]);
        $this->sleepMs = $sleepMs ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
    }

    /** @return array{nip: string, legal_name: string, vat_status: string, registered_at: string} */
    public function company(string $nip): array
    {
        return $this->get('/companies/'.rawurlencode($nip));
    }

    public function courierAssignment(string $nip): string
    {
        $courier = $this->get('/couriers/assignments/'.rawurlencode($nip))['courier_id'] ?? null;
        if (! \is_string($courier) || $courier === '') {
            throw new RegistryUnavailable('Rejestr: brak courier_id w odpowiedzi.');
        }

        return $courier;
    }

    /** @return list<array{courier_id: string, on_time_rate: float, rated_at: string}> */
    public function ratings(?string $since = null): array
    {
        /** @var list<array{courier_id: string, on_time_rate: float, rated_at: string}> */
        return $this->get('/couriers/ratings', $since !== null ? ['since' => $since] : []);
    }

    /**
     * @param  array<string, string>  $query
     * @return array<mixed>
     */
    private function get(string $path, array $query = []): array
    {
        $attempts = \count(self::BACKOFF_MS) + 1;
        for ($attempt = 0;; $attempt++) {
            try {
                return $this->http->request('GET', rtrim($this->registryBaseUrl, '/').$path, ['query' => $query])->toArray();
            } catch (\Throwable $e) {
                if ($attempt + 1 >= $attempts) {
                    throw new RegistryUnavailable(\sprintf('Rejestr niedostępny (%s): %s', $path, $e->getMessage()), 0, $e);
                }
                ($this->sleepMs)(self::BACKOFF_MS[$attempt]);
            }
        }
    }
}
