<?php

namespace App\Security;

/**
 * Alert detekcji nadużyć (ETAP 15): jeden kształt dla percolatora, ES|QL i agregacji.
 *
 * Klucz alertu to reguła + encja + początek okna; `_id` dokumentu to jego sha1, więc ponowne
 * wykrycie tego samego (powtórka z kolejki, `security:detect --since`) nadpisuje dokument
 * zamiast go dublować. Czas zawsze w UTC (ATOM), żeby ten sam moment dawał to samo id.
 */
final readonly class Alert
{
    /** @param array<string, scalar|null> $evidence */
    public function __construct(
        public string $ruleId,
        public string $ruleKind,
        public string $severity,
        public string $entityType,
        public string $entityId,
        public \DateTimeImmutable $windowStart,
        public \DateTimeImmutable $windowEnd,
        public string $message,
        public array $evidence,
        public bool $synthetic,
    ) {
    }

    public function id(): string
    {
        return sha1(\sprintf('%s|%s|%s|%s', $this->ruleId, $this->entityType, $this->entityId, self::iso($this->windowStart)));
    }

    /** @return array<string, mixed> dokument zgodny ze ścisłym mapowaniem `alerts-security` */
    public function toDocument(): array
    {
        return [
            '@timestamp' => self::iso($this->windowStart),
            'rule' => ['id' => $this->ruleId, 'kind' => $this->ruleKind],
            'severity' => $this->severity,
            'entity' => ['type' => $this->entityType, 'id' => $this->entityId],
            'window' => ['start' => self::iso($this->windowStart), 'end' => self::iso($this->windowEnd)],
            'message' => $this->message,
            'evidence' => $this->evidence,
            'labels' => ['synthetic' => $this->synthetic],
        ];
    }

    private static function iso(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM);
    }
}
