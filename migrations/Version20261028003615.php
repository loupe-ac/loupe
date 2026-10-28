<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261028003615 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add work_hold_since to work requests, so the end of a project hold restarts the timeout of each request the hold found open';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE work_requests ADD work_hold_since TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE work_requests DROP work_hold_since');
    }
}
