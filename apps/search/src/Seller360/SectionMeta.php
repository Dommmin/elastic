<?php

namespace App\Seller360;

/**
 * Metadane sekcji seller-360 (`_meta`): wersja ze źródła, czas zdarzenia i stan.
 * `updated_at` ustawia dopiero writer, w chwili zapisu.
 */
final readonly class SectionMeta
{
    public function __construct(
        public int $version,
        public \DateTimeImmutable $sourceTs,
        public string $status = 'ok',
        public ?string $error = null,
    ) {
    }

    /**
     * @return array{version: int, source_ts: string, updated_at: string, status: string, error: ?string}
     */
    public function toArray(\DateTimeImmutable $updatedAt): array
    {
        return [
            'version' => $this->version,
            'source_ts' => self::iso($this->sourceTs),
            'updated_at' => self::iso($updatedAt),
            'status' => $this->status,
            'error' => $this->error,
        ];
    }

    private static function iso(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
