<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261107220000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Seed the insights.refresh_delay_seconds flag so upgraded instances can change the grouping delay';
    }

    /**
     * The installer seeds this flag, but it only ever runs once, so an instance
     * that upgrades has no row, and the admin cannot offer a field for a flag
     * it does not know about.
     */
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO feature_flag (name, type, value, tags, options)
            SELECT 'insights.refresh_delay_seconds', 'int', '60', '[]', NULL
            WHERE NOT EXISTS (
                SELECT 1 FROM feature_flag WHERE name = 'insights.refresh_delay_seconds'
            )
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM feature_flag WHERE name = 'insights.refresh_delay_seconds'");
    }
}
