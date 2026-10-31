<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261031133809 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the session and week usage change to the worker run facts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_worker_run_facts ADD session_usage_delta DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE bridge_worker_run_facts ADD week_usage_delta DOUBLE PRECISION DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_worker_run_facts DROP session_usage_delta');
        $this->addSql('ALTER TABLE bridge_worker_run_facts DROP week_usage_delta');
    }
}
