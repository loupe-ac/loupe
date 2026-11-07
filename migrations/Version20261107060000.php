<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261107060000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the merge question table of the inbox';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE inbox_merge_approval_questions (id UUID NOT NULL, repository_id UUID NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, ended_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, answer VARCHAR(10) DEFAULT NULL, pull_request_url VARCHAR(512) DEFAULT NULL, project_id UUID NOT NULL, item_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_39B9121B126F525E ON inbox_merge_approval_questions (item_id)');
        $this->addSql('CREATE INDEX IDX_39B9121B166D1F9C ON inbox_merge_approval_questions (project_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_inbox_merge_approval_questions_open_repository ON inbox_merge_approval_questions (repository_id) WHERE (ended_at IS NULL)');
        $this->addSql('ALTER TABLE inbox_merge_approval_questions ADD CONSTRAINT FK_INBOX_MERGE_APPROVAL_QUESTIONS_PROJECT FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE inbox_merge_approval_questions ADD CONSTRAINT FK_INBOX_MERGE_APPROVAL_QUESTIONS_ITEM FOREIGN KEY (item_id) REFERENCES inbox_items (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE inbox_merge_approval_questions');
    }
}
