<?php

namespace App\Entity;

use App\Repository\ProcessedEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Deduplikacja zdarzeń po stronie konsumenta (docs/05-SPOJNOSC-DANYCH.md,
 * sekcja 3, [4]). RabbitMQ gwarantuje AT-LEAST-ONCE, więc redelivery po
 * padzie konsumenta w połowie przetwarzania jest PEWNOŚCIĄ, nie ryzykiem.
 *
 * Klucz złożony (event_id, handler) — NIE sam event_id — bo różni
 * konsumenci (sync, analytics, alerts) mogą przetwarzać ten sam event_id,
 * gdyby kiedyś ta sama koperta trafiła do więcej niż jednej kolejki.
 */
#[ORM\Entity(repositoryClass: ProcessedEventRepository::class)]
#[ORM\Table(name: 'processed_events')]
#[ORM\UniqueConstraint(name: 'uniq_event_handler', columns: ['event_id', 'handler'])]
#[ORM\Index(name: 'idx_processed_at', columns: ['processed_at'])]
class ProcessedEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(length: 26)]
    private string $eventId;

    #[ORM\Column(length: 100)]
    private string $handler;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $processedAt;

    public function __construct(string $eventId, string $handler)
    {
        $this->eventId = $eventId;
        $this->handler = $handler;
        $this->processedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getHandler(): string
    {
        return $this->handler;
    }

    public function getProcessedAt(): \DateTimeImmutable
    {
        return $this->processedAt;
    }
}
