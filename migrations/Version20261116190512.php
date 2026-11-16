<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261116190512 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Index outbox_events on (project_id, sequence) for the latest-sequence read';
    }

    /** CREATE INDEX CONCURRENTLY cannot run inside a transaction. */
    #[\Override]
    public function isTransactional(): bool
    {
        return false;
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_outbox_events_project_sequence');
        $this->addSql('CREATE INDEX CONCURRENTLY idx_outbox_events_project_sequence ON outbox_events (project_id, sequence)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_outbox_events_project_sequence');
    }
}
