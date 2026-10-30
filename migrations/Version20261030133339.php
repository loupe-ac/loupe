<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030133339 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Replace the bridge.interactive_stale_close_enabled flag with bridge.interactive_stale_after_seconds';
    }

    /**
     * An instance that switched the sweep off gets 0, which keeps it off.
     */
    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO feature_flag (name, type, value, tags, options)
            SELECT 'bridge.interactive_stale_after_seconds', 'int',
                CASE WHEN EXISTS (
                    SELECT 1 FROM feature_flag
                    WHERE name = 'bridge.interactive_stale_close_enabled' AND value #>> '{}' = 'false'
                ) THEN '0'::json ELSE '43200'::json END,
                '[]', NULL
            WHERE NOT EXISTS (
                SELECT 1 FROM feature_flag WHERE name = 'bridge.interactive_stale_after_seconds'
            )
            SQL);
        $this->addSql("DELETE FROM feature_flag WHERE name = 'bridge.interactive_stale_close_enabled'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO feature_flag (name, type, value, tags, options)
            SELECT 'bridge.interactive_stale_close_enabled', 'bool',
                CASE WHEN EXISTS (
                    SELECT 1 FROM feature_flag
                    WHERE name = 'bridge.interactive_stale_after_seconds' AND value #>> '{}' = '0'
                ) THEN 'false'::json ELSE 'true'::json END,
                '[]', NULL
            WHERE NOT EXISTS (
                SELECT 1 FROM feature_flag WHERE name = 'bridge.interactive_stale_close_enabled'
            )
            SQL);
        $this->addSql("DELETE FROM feature_flag WHERE name = 'bridge.interactive_stale_after_seconds'");
    }
}
