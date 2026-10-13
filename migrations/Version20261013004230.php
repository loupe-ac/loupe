<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013004230 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Store the markers of a card and the time they are due, with partial indexes for the minute task';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_cards ADD markers JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE board_cards ADD markers_due_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_board_cards_markers_due ON board_cards (markers_due_at) WHERE (markers_due_at IS NOT NULL)');
        $this->addSql('CREATE INDEX idx_board_cards_markers_missing ON board_cards (project_id) WHERE (markers IS NULL)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_board_cards_markers_missing');
        $this->addSql('DROP INDEX idx_board_cards_markers_due');
        $this->addSql('ALTER TABLE board_cards DROP markers_due_at');
        $this->addSql('ALTER TABLE board_cards DROP markers');
    }
}
