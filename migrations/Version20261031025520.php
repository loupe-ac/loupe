<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261031025520 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the billed cost table of worker runs and flag the usage rows of billed runs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bridge_worker_run_billed_costs (state VARCHAR(20) NOT NULL, reason VARCHAR(20) DEFAULT NULL, cost_usd NUMERIC(12, 6) DEFAULT NULL, attempts INT NOT NULL, first_read_cost NUMERIC(12, 6) DEFAULT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, run_id UUID NOT NULL, run_key UUID NOT NULL, subject_type VARCHAR(40) NOT NULL, subject_id UUID NOT NULL, run_started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (run_id))');
        $this->addSql('CREATE INDEX IDX_F6F76E5B166D1F9C ON bridge_worker_run_billed_costs (project_id)');
        $this->addSql('CREATE INDEX idx_bridge_worker_run_billed_costs_subject ON bridge_worker_run_billed_costs (project_id, subject_type, subject_id)');
        $this->addSql('ALTER TABLE bridge_worker_run_billed_costs ADD CONSTRAINT FK_F6F76E5B166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE bridge_worker_run_usage ADD billed_cost BOOLEAN DEFAULT false NOT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_worker_run_usage DROP billed_cost');
        $this->addSql('ALTER TABLE bridge_worker_run_billed_costs DROP CONSTRAINT FK_F6F76E5B166D1F9C');
        $this->addSql('DROP TABLE bridge_worker_run_billed_costs');
    }
}
