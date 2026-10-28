<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261028201540 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Seed the bridge.interactive_stale_close_enabled flag so upgraded instances close stale interactive sessions';
    }

    /**
     * The installer seeds this flag once, so an instance that upgrades has no
     * row. Seeded on, matching the installer.
     */
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO feature_flag (name, type, value, tags, options)
            SELECT 'bridge.interactive_stale_close_enabled', 'bool', 'true', '[]', NULL
            WHERE NOT EXISTS (
                SELECT 1 FROM feature_flag WHERE name = 'bridge.interactive_stale_close_enabled'
            )
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM feature_flag WHERE name = 'bridge.interactive_stale_close_enabled'");
    }
}
