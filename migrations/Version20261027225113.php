<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261027225113 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the table that ties a repository misfit notice to its project';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE inbox_repository_misfits (id UUID NOT NULL, ended_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, repositories JSON NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, project_id UUID NOT NULL, item_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_35A7E3F9166D1F9C ON inbox_repository_misfits (project_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_35A7E3F9126F525E ON inbox_repository_misfits (item_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_inbox_repository_misfits_open_project ON inbox_repository_misfits (project_id) WHERE (ended_at IS NULL)');
        $this->addSql('ALTER TABLE inbox_repository_misfits ADD CONSTRAINT FK_35A7E3F9166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE inbox_repository_misfits ADD CONSTRAINT FK_35A7E3F9126F525E FOREIGN KEY (item_id) REFERENCES inbox_items (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inbox_repository_misfits DROP CONSTRAINT FK_35A7E3F9166D1F9C');
        $this->addSql('ALTER TABLE inbox_repository_misfits DROP CONSTRAINT FK_35A7E3F9126F525E');
        $this->addSql('DROP TABLE inbox_repository_misfits');
    }
}
