<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261029014500 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the harness event counts to the worker runs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_worker_runs ADD harness_events JSONB DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_worker_runs DROP harness_events');
    }
}
