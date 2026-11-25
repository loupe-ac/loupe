<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261123215456 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the table of the waits of workers';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bridge_worker_waits (id UUID NOT NULL, ended_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, end_reason VARCHAR(20) DEFAULT NULL, met_conditions JSONB DEFAULT NULL, resume_run_id UUID DEFAULT NULL, card_id UUID NOT NULL, run_id UUID NOT NULL, chain_root_run_id UUID NOT NULL, bridge_id UUID NOT NULL, session_id UUID NOT NULL, conditions JSONB NOT NULL, opened_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, limit_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_A575A487166D1F9C ON bridge_worker_waits (project_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_bridge_worker_wait_run ON bridge_worker_waits (run_id)');
        $this->addSql('CREATE INDEX idx_bridge_worker_waits_chain ON bridge_worker_waits (chain_root_run_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_bridge_worker_wait_open_card ON bridge_worker_waits (card_id) WHERE (ended_at IS NULL)');
        $this->addSql('ALTER TABLE bridge_worker_waits ADD CONSTRAINT FK_A575A487166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_worker_waits DROP CONSTRAINT FK_A575A487166D1F9C');
        $this->addSql('DROP TABLE bridge_worker_waits');
    }
}
