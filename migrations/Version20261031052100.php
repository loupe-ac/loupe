<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261031052100 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the bridge account readings table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bridge_account_readings (id UUID NOT NULL, account VARCHAR(40) NOT NULL, window_key VARCHAR(16) NOT NULL, used_percent DOUBLE PRECISION DEFAULT NULL, resets_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, limited BOOLEAN NOT NULL, source VARCHAR(40) NOT NULL, read_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, owner_id UUID NOT NULL, bridge_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_C5F053CB7E3C61F94948DF55 ON bridge_account_readings (owner_id, bridge_id)');
        $this->addSql('CREATE INDEX idx_bridge_account_readings_latest ON bridge_account_readings (owner_id, bridge_id, account, window_key, read_at)');
        $this->addSql('ALTER TABLE bridge_account_readings ADD CONSTRAINT FK_C5F053CB7E3C61F94948DF55 FOREIGN KEY (owner_id, bridge_id) REFERENCES bridges (owner_id, id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE bridge_account_readings');
    }
}
