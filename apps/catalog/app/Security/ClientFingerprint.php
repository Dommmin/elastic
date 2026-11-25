<?php

namespace App\Security;

use Illuminate\Support\Facades\Log;

/**
 * Odcisk klienta recenzji (ETAP 15): z surowego IP robi hasz HMAC i prefiks sieci,
 * żeby samo IP nigdy nie trafiło do Postgresa, outboxu, RabbitMQ ani ES.
 *
 * Hasz = pierwsze 16 znaków hex z HMAC-SHA256(IP, pepper). Pepper jest tajny (tylko w `.env`),
 * więc z haszu nie da się odtworzyć adresu przeszukaniem 2^32 możliwości IPv4.
 */
final class ClientFingerprint
{
    private const HASH_LENGTH = 16;

    private const USER_AGENT_MAX = 256;

    /** Ostrzeżenie o pustym pepperze raz na proces, żeby nie zalewać logów. */
    private static bool $warned = false;

    public function __construct(private readonly ?string $pepper) {}

    /** Hasz IP; null przy pustym pepperze (z ostrzeżeniem) albo niepoprawnym adresie. */
    public function hash(string $ip): ?string
    {
        if ($this->pepper === null || $this->pepper === '') {
            if (! self::$warned) {
                self::$warned = true;
                Log::warning('SECURITY_IP_PEPPER jest pusty: hasz IP recenzji nie będzie zapisywany.');
            }

            return null;
        }

        $normalized = self::normalize($ip);

        if ($normalized === null) {
            return null;
        }

        return substr(hash_hmac('sha256', $normalized, $this->pepper), 0, self::HASH_LENGTH);
    }

    /** Prefiks sieci tekstowo: `/24` dla IPv4 (też mapowanego), `/48` dla IPv6; null przy niepoprawnym IP. */
    public static function prefix(string $ip): ?string
    {
        $normalized = self::normalize($ip);

        if ($normalized === null) {
            return null;
        }

        $packed = inet_pton($normalized);
        $isV4 = strlen($packed) === 4;
        $keep = $isV4 ? 3 : 6;

        $masked = substr($packed, 0, $keep).str_repeat("\0", strlen($packed) - $keep);

        return inet_ntop($masked).'/'.($isV4 ? 24 : 48);
    }

    public static function userAgent(?string $ua): ?string
    {
        return $ua === null ? null : mb_substr($ua, 0, self::USER_AGENT_MAX);
    }

    /** Tylko do testów: pozwala ponownie sprawdzić ostrzeżenie „raz na proces”. */
    public static function resetWarning(): void
    {
        self::$warned = false;
    }

    /** Poprawny IP w postaci kanonicznej; adres IPv4-mapowany (::ffff:a.b.c.d) sprowadzony do IPv4. */
    private static function normalize(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = inet_pton($ip);

        if ($packed === false) {
            return null;
        }

        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            $packed = substr($packed, 12);
        }

        return inet_ntop($packed);
    }
}
