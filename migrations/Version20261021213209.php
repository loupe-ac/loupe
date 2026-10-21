<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261021213209 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Store the board set, the inbox set and the due time of the card markers in new columns, and copy the old marker columns into them';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_cards ADD workflow_markers JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE board_cards ADD workflow_markers_due_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE board_cards ADD inbox_markers JSONB DEFAULT NULL');
        $this->addSql('UPDATE board_cards SET workflow_markers = markers, workflow_markers_due_at = markers_due_at');
        $this->addSql('CREATE INDEX idx_board_cards_workflow_markers_due ON board_cards (workflow_markers_due_at) WHERE (workflow_markers_due_at IS NOT NULL)');
        $this->addSql('CREATE INDEX idx_board_cards_inbox_markers_missing ON board_cards (project_id) WHERE (inbox_markers IS NULL)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_board_cards_inbox_markers_missing');
        $this->addSql('DROP INDEX idx_board_cards_workflow_markers_due');
        $this->addSql('ALTER TABLE board_cards DROP inbox_markers');
        $this->addSql('ALTER TABLE board_cards DROP workflow_markers_due_at');
        $this->addSql('ALTER TABLE board_cards DROP workflow_markers');
    }
}
