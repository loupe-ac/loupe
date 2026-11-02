<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101235138 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Seed the write request budget flag of the public API';
    }

    /**
     * The installer seeds this flag, but it only ever runs once, so an instance
     * that upgrades has no row and the admin cannot offer the number for editing.
     *
     * Seeded at the coded default, so the row and the fallback start out equal.
     */
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO feature_flag (name, type, value, tags, options)
            SELECT 'api.rate_limit_writes_per_minute', 'int', '120', '[]', NULL
            WHERE NOT EXISTS (
                SELECT 1 FROM feature_flag WHERE name = 'api.rate_limit_writes_per_minute'
            )
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM feature_flag WHERE name = 'api.rate_limit_writes_per_minute'");
    }
}
