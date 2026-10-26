<?php

namespace App\Support\ErrorTracking;

/**
 * Znacznik testu `make errors-smoke`: trafia do treści wyjątku, żeby smoke
 * znalazł dokładnie swój issue w GlitchTipie. Dopuszczamy tylko bezpieczne znaki,
 * bo pochodzi z query stringa.
 */
final class SmokeMarker
{
    public static function clean(mixed $raw): string
    {
        $marker = substr((string) preg_replace('/[^A-Za-z0-9._-]/', '', is_string($raw) ? $raw : ''), 0, 64);

        return $marker === '' ? 'none' : $marker;
    }
}
