<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tabela idempotencji konsumenta (docs/05-SPOJNOSC-DANYCH.md, sekcja 3, [4]).
 *
 * Napisana ręcznie, nie przez `doctrine:migrations:diff` — ten wymaga
 * żywego połączenia z bazą do porównania schematu, a Docker jest celowo
 * wyłączony na czas tego etapu. Kształt 1:1 z App\Entity\ProcessedEvent.
 */
final class Version20260101000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tabela processed_events — deduplikacja zdarzeń AMQP po (event_id, handler)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE processed_events (
                id BIGSERIAL PRIMARY KEY,
                event_id VARCHAR(26) NOT NULL,
                handler VARCHAR(100) NOT NULL,
                processed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
            )
            SQL);

        $this->addSql(
            'CREATE UNIQUE INDEX uniq_event_handler ON processed_events (event_id, handler)',
        );
        $this->addSql(
            'CREATE INDEX idx_processed_at ON processed_events (processed_at)',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE processed_events');
    }
}
