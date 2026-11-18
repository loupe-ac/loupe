<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261117040100 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Seed the workflow.full_sweep_interval_hours flag at 24 for an instance that upgrades';
    }

    /**
     * /install runs once, so an instance that upgrades holds no row for a new flag.
     * The row keeps an operator's own value when one exists.
     */
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO feature_flag (name, type, value, tags, options)
            SELECT 'workflow.full_sweep_interval_hours', 'int', '24', '[]', NULL
            WHERE NOT EXISTS (
                SELECT 1 FROM feature_flag WHERE name = 'workflow.full_sweep_interval_hours'
            )
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM feature_flag WHERE name = 'workflow.full_sweep_interval_hours'");
    }
}
