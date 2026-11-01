<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101142300 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Drop the unread card search columns and the two document search indexes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_documents_search_vector');
        $this->addSql('DROP INDEX IF EXISTS idx_documents_project_search_language');
        // @contract-phase: the app stopped every read and write of the column in the release before this one.
        $this->addSql('ALTER TABLE board_cards DROP COLUMN search_vector');
        // @contract-phase: the app stopped every read and write of the column in the release before this one.
        $this->addSql('ALTER TABLE board_cards DROP COLUMN search_language');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_cards ADD search_vector TSVECTOR DEFAULT NULL');
        $this->addSql("ALTER TABLE board_cards ADD search_language VARCHAR(20) DEFAULT 'english' NOT NULL");
        $this->addSql('CREATE INDEX idx_documents_search_vector ON documents USING gin (search_vector)');
        $this->addSql('CREATE INDEX idx_documents_project_search_language ON documents (project_id, search_language)');
    }
}
