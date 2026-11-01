<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101200051 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the bridge claim refusals table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bridge_claim_refusals (id UUID NOT NULL, account VARCHAR(40) DEFAULT NULL, owner_id UUID NOT NULL, bridge_id UUID NOT NULL, work_request_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_CDE159527E3C61F94948DF55 ON bridge_claim_refusals (owner_id, bridge_id)');
        $this->addSql('CREATE INDEX IDX_CDE15952591D1576 ON bridge_claim_refusals (work_request_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_bridge_claim_refusals_bridge_request ON bridge_claim_refusals (bridge_id, work_request_id)');
        $this->addSql('ALTER TABLE bridge_claim_refusals ADD CONSTRAINT FK_CDE159527E3C61F94948DF55 FOREIGN KEY (owner_id, bridge_id) REFERENCES bridges (owner_id, id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE bridge_claim_refusals ADD CONSTRAINT FK_CDE15952591D1576 FOREIGN KEY (work_request_id) REFERENCES work_requests (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE bridge_claim_refusals');
    }
}
