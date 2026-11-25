<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261125144845 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Store each worker run fact and run-finished card event that ended waiting on the forge as unfinished';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE bridge_worker_run_facts SET outcome = 'unfinished' WHERE outcome = 'waiting-on-forge'");
        $this->addSql(<<<'SQL'
            UPDATE board_card_events SET detail = jsonb_set(detail, '{state}', '"unfinished"')
            WHERE kind = 'run-finished' AND detail->>'state' = 'waiting-on-forge'
            SQL);
    }

    /** The two states cannot be told apart again, so nothing restores them. */
    #[\Override]
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('A run stored as unfinished cannot be told from one that waited on the forge.');
    }
}
