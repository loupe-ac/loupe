<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261117040000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Seed the workflow.change_driven_sweep flag on for an instance that upgrades';
    }

    /**
     * /install runs once, so an instance that upgrades holds no row for a new flag.
     * The row keeps an operator's own value when one exists.
     */
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO feature_flag (name, type, value, tags, options)
            SELECT 'workflow.change_driven_sweep', 'bool', 'true', '[]', NULL
            WHERE NOT EXISTS (
                SELECT 1 FROM feature_flag WHERE name = 'workflow.change_driven_sweep'
            )
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM feature_flag WHERE name = 'workflow.change_driven_sweep'");
    }
}
