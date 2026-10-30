<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030172448 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create bridge_experiment_archives';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bridge_experiment_archives (id UUID NOT NULL, experiment VARCHAR(64) NOT NULL, archived_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, covered_run_ids JSON NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_D06E2889166D1F9C ON bridge_experiment_archives (project_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_bridge_experiment_archive ON bridge_experiment_archives (project_id, experiment)');
        $this->addSql('ALTER TABLE bridge_experiment_archives ADD CONSTRAINT FK_D06E2889166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_experiment_archives DROP CONSTRAINT FK_D06E2889166D1F9C');
        $this->addSql('DROP TABLE bridge_experiment_archives');
    }
}
