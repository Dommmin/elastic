<?php

namespace App\Repository;

use App\Entity\ProcessedEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProcessedEvent>
 */
class ProcessedEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProcessedEvent::class);
    }

    /**
     * Atomowo oznacza (event_id, handler) jako przetworzone i mówi, czy TO
     * WYWOŁANIE było pierwsze.
     *
     * DLACZEGO `INSERT ... ON CONFLICT DO NOTHING`, a nie "SELECT czy
     * istnieje, potem INSERT" (docs/05-SPOJNOSC-DANYCH.md, sekcja 3, [4]):
     * dwa równoległe konsumenty mogłyby między SELECT-em a INSERT-em oba
     * dojść do wniosku "jeszcze nie przetworzone" (klasyczny wyścig
     * check-then-act) i oba wykonać efekt uboczny dwa razy. Jedno zapytanie
     * SQL z gwarancją unikalności bazy nie ma tego okna.
     *
     * @return bool true = pierwsze przetworzenie (rób dalej), false = duplikat (ack i pomiń)
     */
    public function tryMarkProcessed(string $eventId, string $handler): bool
    {
        $connection = $this->getEntityManager()->getConnection();

        $affected = $connection->executeStatement(
            <<<'SQL'
                INSERT INTO processed_events (event_id, handler, processed_at)
                VALUES (:event_id, :handler, NOW())
                ON CONFLICT (event_id, handler) DO NOTHING
                SQL,
            ['event_id' => $eventId, 'handler' => $handler],
        );

        return $affected === 1;
    }
}
