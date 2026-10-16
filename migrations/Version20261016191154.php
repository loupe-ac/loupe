<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016191154 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the document agent reviews table';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE document_agent_reviews (id UUID NOT NULL, findings JSON NOT NULL, answers JSON DEFAULT NULL, answered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, version_number INT NOT NULL, summary TEXT NOT NULL, conclusion VARCHAR(20) NOT NULL, worker_run_id UUID DEFAULT NULL, work_request_id UUID DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, project_id UUID NOT NULL, card_id UUID NOT NULL, document_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_9C8C2D2E166D1F9C ON document_agent_reviews (project_id)');
        $this->addSql('CREATE INDEX IDX_9C8C2D2E4ACC9A20 ON document_agent_reviews (card_id)');
        $this->addSql('CREATE INDEX IDX_9C8C2D2EC33F7837 ON document_agent_reviews (document_id)');
        $this->addSql('CREATE INDEX idx_document_agent_reviews_document_created ON document_agent_reviews (document_id, created_at)');
        $this->addSql('ALTER TABLE document_agent_reviews ADD CONSTRAINT FK_9C8C2D2E166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE document_agent_reviews ADD CONSTRAINT FK_9C8C2D2E4ACC9A20 FOREIGN KEY (card_id) REFERENCES board_cards (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE document_agent_reviews ADD CONSTRAINT FK_9C8C2D2EC33F7837 FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document_agent_reviews DROP CONSTRAINT FK_9C8C2D2E166D1F9C');
        $this->addSql('ALTER TABLE document_agent_reviews DROP CONSTRAINT FK_9C8C2D2E4ACC9A20');
        $this->addSql('ALTER TABLE document_agent_reviews DROP CONSTRAINT FK_9C8C2D2EC33F7837');
        $this->addSql('DROP TABLE document_agent_reviews');
    }
}
