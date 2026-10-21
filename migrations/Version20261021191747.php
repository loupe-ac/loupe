<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261021191747 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Seed the site.link.discord flag so an upgraded instance can set the community chat link';
    }

    /**
     * The installer seeds this flag, but it only ever runs once, so an instance
     * that upgrades has no row, and the flags page cannot offer a field for a
     * flag it does not know about. Seeded empty: the link stays hidden until an admin sets it.
     */
    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO feature_flag (name, type, value, tags, options)
            SELECT 'site.link.discord', 'string', '""', '[]', NULL
            WHERE NOT EXISTS (
                SELECT 1 FROM feature_flag WHERE name = 'site.link.discord'
            )
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM feature_flag WHERE name = 'site.link.discord'");
    }
}
