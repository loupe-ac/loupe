<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261028201512 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the last activity time and the close reason to worker runs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_worker_runs ADD last_activity_at TIMESTAMP(6) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE bridge_worker_runs ADD close_reason VARCHAR(20) DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_worker_runs DROP close_reason');
        $this->addSql('ALTER TABLE bridge_worker_runs DROP last_activity_at');
    }
}
