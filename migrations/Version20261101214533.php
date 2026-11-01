<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101214533 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Seed the two request budget flags of the public API';
    }

    /**
     * The installer seeds these flags, but it only ever runs once, so an instance
     * that upgrades has no row and the admin cannot offer the numbers for editing.
     *
     * Seeded at the coded defaults, so the row and the fallback start out equal.
     */
    public function up(Schema $schema): void
    {
        foreach (['api.rate_limit_reads_per_minute' => '600', 'api.rate_limit_writes_per_minute' => '120'] as $name => $value) {
            $this->addSql(<<<'SQL'
                INSERT INTO feature_flag (name, type, value, tags, options)
                SELECT :name, 'int', :value, '[]', NULL
                WHERE NOT EXISTS (
                    SELECT 1 FROM feature_flag WHERE name = :name
                )
                SQL, ['name' => $name, 'value' => $value]);
        }
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM feature_flag WHERE name IN ('api.rate_limit_reads_per_minute', 'api.rate_limit_writes_per_minute')");
    }
}
