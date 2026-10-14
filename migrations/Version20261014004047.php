<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014004047 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the inbox action id and item number to work requests and bridge commands';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_commands ADD inbox_action_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE bridge_commands ADD inbox_item_number INT DEFAULT NULL');
        $this->addSql('ALTER TABLE work_requests ADD inbox_action_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE work_requests ADD inbox_item_number INT DEFAULT NULL');
        $this->addSql('CREATE INDEX inbox_actions_work_request_idx ON inbox_actions (work_request_id)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX inbox_actions_work_request_idx');
        $this->addSql('ALTER TABLE bridge_commands DROP inbox_action_id');
        $this->addSql('ALTER TABLE bridge_commands DROP inbox_item_number');
        $this->addSql('ALTER TABLE work_requests DROP inbox_action_id');
        $this->addSql('ALTER TABLE work_requests DROP inbox_item_number');
    }
}
