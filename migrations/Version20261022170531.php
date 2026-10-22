<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022170531 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the entry check columns to document_versions';
    }

    /** Writes no row on purpose. A document that is in review today has no entry check, so no rule approves it. */
    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document_versions ADD entered_review_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE document_versions ADD entry_unanswered_decisions INT DEFAULT NULL');
        $this->addSql('ALTER TABLE document_versions ADD entry_open_threads INT DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document_versions DROP entered_review_at');
        $this->addSql('ALTER TABLE document_versions DROP entry_unanswered_decisions');
        $this->addSql('ALTER TABLE document_versions DROP entry_open_threads');
    }
}
