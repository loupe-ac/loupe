<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261024213116 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Seed the notifications.web_push.enabled flag so upgraded instances can switch it on';
    }

    /**
     * The installer seeds this flag, but it only ever runs once, so an instance
     * that upgrades has no row, and the admin cannot offer a switch for a flag
     * it does not know about.
     *
     * Seeded off, matching the installer. The operator sets the key pair first.
     */
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO feature_flag (name, type, value, tags, options)
            SELECT 'notifications.web_push.enabled', 'bool', 'false', '[]', NULL
            WHERE NOT EXISTS (
                SELECT 1 FROM feature_flag WHERE name = 'notifications.web_push.enabled'
            )
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM feature_flag WHERE name = 'notifications.web_push.enabled'");
    }
}
