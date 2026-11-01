<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261031133235 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the bridge work matches table and add the wait columns to work requests';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bridge_work_matches (id UUID NOT NULL, account VARCHAR(40) NOT NULL, owner_id UUID NOT NULL, bridge_id UUID NOT NULL, work_request_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_A12C9AB47E3C61F94948DF55 ON bridge_work_matches (owner_id, bridge_id)');
        $this->addSql('CREATE INDEX IDX_A12C9AB4591D1576 ON bridge_work_matches (work_request_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_bridge_work_matches_bridge_request ON bridge_work_matches (bridge_id, work_request_id)');
        $this->addSql('ALTER TABLE bridge_work_matches ADD CONSTRAINT FK_A12C9AB47E3C61F94948DF55 FOREIGN KEY (owner_id, bridge_id) REFERENCES bridges (owner_id, id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE bridge_work_matches ADD CONSTRAINT FK_A12C9AB4591D1576 FOREIGN KEY (work_request_id) REFERENCES work_requests (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE work_requests ADD wait_cause VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE work_requests ADD wait_account VARCHAR(40) DEFAULT NULL');
        $this->addSql('ALTER TABLE work_requests ADD wait_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE bridge_work_matches');
        $this->addSql('ALTER TABLE work_requests DROP wait_cause');
        $this->addSql('ALTER TABLE work_requests DROP wait_account');
        $this->addSql('ALTER TABLE work_requests DROP wait_until');
    }
}
