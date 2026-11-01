<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261028121530 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add trigram indexes for the substring search of cards and documents, and the documents search_text column';
    }

    public function up(Schema $schema): void
    {
        // Needs a role that may create the extension. The managed production
        // cluster must allow it before this runs.
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        $this->addSql('ALTER TABLE documents ADD search_text TEXT DEFAULT \'\' NOT NULL');
        // DISTINCT ON picks the highest version number.
        $this->addSql(<<<'SQL'
            UPDATE documents d
            SET search_text = v.markdown_source
            FROM (
                SELECT DISTINCT ON (document_id) document_id, markdown_source
                FROM document_versions
                ORDER BY document_id, version_number DESC
            ) v
            WHERE v.document_id = d.id
            SQL);
        // Written by hand: Doctrine maps no expression index. The expression is
        // the one the search query writes, so Postgres can use the index.
        $this->addSql('CREATE INDEX idx_board_cards_title_trgm ON board_cards USING gin (lower(title) gin_trgm_ops)');
        $this->addSql('CREATE INDEX idx_board_cards_body_trgm ON board_cards USING gin (lower(body) gin_trgm_ops)');
        $this->addSql('CREATE INDEX idx_documents_title_trgm ON documents USING gin (lower(title) gin_trgm_ops)');
        $this->addSql('CREATE INDEX idx_documents_search_text_trgm ON documents USING gin (lower(search_text) gin_trgm_ops)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_documents_search_text_trgm');
        $this->addSql('DROP INDEX idx_documents_title_trgm');
        $this->addSql('DROP INDEX idx_board_cards_body_trgm');
        $this->addSql('DROP INDEX idx_board_cards_title_trgm');
        $this->addSql('ALTER TABLE documents DROP search_text');
    }
}
