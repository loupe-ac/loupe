<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124120000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Store each worker run that ended waiting on the forge as unfinished, because the waiting result is replaced by the wait tool';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE bridge_worker_runs SET state = 'unfinished' WHERE state = 'waiting-on-forge'");
        $this->addSql("UPDATE bridge_worker_run_states SET state = 'unfinished' WHERE state = 'waiting-on-forge'");
    }

    /** The two states cannot be told apart again, so nothing restores them. */
    #[\Override]
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('A run stored as unfinished cannot be told from one that waited on the forge.');
    }
}
