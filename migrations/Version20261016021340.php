<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016021340 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the harness, CLI and skill versions to the worker runs and their fact rows';
    }

    public function up(Schema $schema): void
    {
        foreach (['bridge_worker_runs', 'bridge_worker_run_facts'] as $table) {
            $this->addSql(sprintf('ALTER TABLE %s ADD harness_version VARCHAR(100) DEFAULT NULL', $table));
            $this->addSql(sprintf('ALTER TABLE %s ADD cli_version VARCHAR(100) DEFAULT NULL', $table));
            $this->addSql(sprintf('ALTER TABLE %s ADD skill_version VARCHAR(100) DEFAULT NULL', $table));
        }
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        foreach (['bridge_worker_runs', 'bridge_worker_run_facts'] as $table) {
            $this->addSql(sprintf('ALTER TABLE %s DROP harness_version', $table));
            $this->addSql(sprintf('ALTER TABLE %s DROP cli_version', $table));
            $this->addSql(sprintf('ALTER TABLE %s DROP skill_version', $table));
        }
    }
}
