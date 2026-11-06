<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106160800 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Seed the insights.public_links.enabled flag so upgraded instances can switch public links off';
    }

    /**
     * The installer seeds this flag, but it only ever runs once, so an instance
     * that upgrades has no row, and the admin cannot offer a switch for a flag
     * it does not know about.
     *
     * Seeded on, matching the installer.
     */
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO feature_flag (name, type, value, tags, options)
            SELECT 'insights.public_links.enabled', 'bool', 'true', '[]', NULL
            WHERE NOT EXISTS (
                SELECT 1 FROM feature_flag WHERE name = 'insights.public_links.enabled'
            )
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM feature_flag WHERE name = 'insights.public_links.enabled'");
    }
}
