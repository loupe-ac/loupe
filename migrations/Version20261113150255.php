<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261113150255 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create project milestones, add the set-up flag, and set up every existing project';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE project_milestones (id UUID NOT NULL, "key" VARCHAR(100) NOT NULL, reached_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_51FAB6166D1F9C ON project_milestones (project_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_project_milestone_key ON project_milestones (project_id, "key")');
        $this->addSql('ALTER TABLE project_milestones ADD CONSTRAINT FK_51FAB6166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE projects ADD set_up BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('UPDATE projects SET set_up = true');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project_milestones DROP CONSTRAINT FK_51FAB6166D1F9C');
        $this->addSql('DROP TABLE project_milestones');
        $this->addSql('ALTER TABLE projects DROP set_up');
    }
}
