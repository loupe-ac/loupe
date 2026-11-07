<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261107041933 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the tables of the workflow setting overrides and the stored repository settings';
    }

    /** The repository foreign keys are not in the entity mapping, because the Workflow module cannot map a Forge entity. */
    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE workflow_repository_settings (id UUID NOT NULL, repository_id UUID NOT NULL, setting_key VARCHAR(64) NOT NULL, value TEXT NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_7DDFDF36166D1F9C ON workflow_repository_settings (project_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_workflow_repository_settings_repository_key ON workflow_repository_settings (repository_id, setting_key)');
        $this->addSql('CREATE TABLE workflow_setting_overrides (id UUID NOT NULL, repository_id UUID NOT NULL, setting_key VARCHAR(64) NOT NULL, value TEXT DEFAULT NULL, valid BOOLEAN NOT NULL, blob_sha VARCHAR(64) DEFAULT NULL, head_sha VARCHAR(64) NOT NULL, read_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_48FE3EC6166D1F9C ON workflow_setting_overrides (project_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_workflow_setting_overrides_repository_key ON workflow_setting_overrides (repository_id, setting_key)');
        $this->addSql('ALTER TABLE workflow_repository_settings ADD CONSTRAINT FK_7DDFDF36166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE workflow_setting_overrides ADD CONSTRAINT FK_48FE3EC6166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE workflow_repository_settings ADD CONSTRAINT fk_workflow_repository_settings_repository FOREIGN KEY (repository_id) REFERENCES forge_repositories (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE workflow_setting_overrides ADD CONSTRAINT fk_workflow_setting_overrides_repository FOREIGN KEY (repository_id) REFERENCES forge_repositories (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE workflow_setting_overrides');
        $this->addSql('DROP TABLE workflow_repository_settings');
    }
}
