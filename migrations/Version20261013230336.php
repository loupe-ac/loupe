<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013230336 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the inbox actions table';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE inbox_actions (id UUID NOT NULL, bridge_command_id UUID DEFAULT NULL, work_request_id UUID DEFAULT NULL, pause_id UUID DEFAULT NULL, cancelled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, kind VARCHAR(20) NOT NULL, note TEXT NOT NULL, requested_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, item_id UUID NOT NULL, requested_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_307CF710126F525E ON inbox_actions (item_id)');
        $this->addSql('CREATE INDEX IDX_307CF7104DA1E751 ON inbox_actions (requested_by_id)');
        $this->addSql('CREATE INDEX inbox_actions_bridge_command_idx ON inbox_actions (bridge_command_id)');
        $this->addSql('ALTER TABLE inbox_actions ADD CONSTRAINT FK_307CF710126F525E FOREIGN KEY (item_id) REFERENCES inbox_items (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE inbox_actions ADD CONSTRAINT FK_307CF7104DA1E751 FOREIGN KEY (requested_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inbox_actions DROP CONSTRAINT FK_307CF710126F525E');
        $this->addSql('ALTER TABLE inbox_actions DROP CONSTRAINT FK_307CF7104DA1E751');
        $this->addSql('DROP TABLE inbox_actions');
    }
}
