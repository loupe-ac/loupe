<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261125035206 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Let a discovery run remember the work requests that its reports answered';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE discovery_runs ADD answered_work_request_ids JSON DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE discovery_runs DROP answered_work_request_ids');
    }
}
