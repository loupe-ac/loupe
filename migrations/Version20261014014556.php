<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014014556 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the inbox item id to work requests and bridge commands, and index the inbox action of a work request';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_commands ADD inbox_item_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE work_requests ADD inbox_item_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_work_requests_inbox_action ON work_requests (inbox_action_id)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_work_requests_inbox_action');
        $this->addSql('ALTER TABLE bridge_commands DROP inbox_item_id');
        $this->addSql('ALTER TABLE work_requests DROP inbox_item_id');
    }
}
