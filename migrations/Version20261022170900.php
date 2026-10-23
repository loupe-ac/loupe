<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022170900 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Seed the waitlist.notification_emails flag with the addresses of the active admins';
    }

    /**
     * The installer seeds this flag once, so an upgraded instance has no row.
     * The list starts with every admin who can sign in: not the agent, not a
     * suspended account, and holding ROLE_ADMIN, the rule of countActiveAdmins().
     */
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO feature_flag (name, type, value, tags, options)
            SELECT 'waitlist.notification_emails', 'string',
                to_json(COALESCE((
                    SELECT string_agg(email, ', ' ORDER BY created_at, email)
                    FROM users
                    WHERE id != '1073e0a5-9b1c-42f7-8e44-a10a6e57c3d9'
                      AND suspended_at IS NULL
                      AND jsonb_exists(roles::jsonb, 'ROLE_ADMIN')
                ), '')),
                '[]', NULL
            WHERE NOT EXISTS (
                SELECT 1 FROM feature_flag WHERE name = 'waitlist.notification_emails'
            )
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM feature_flag WHERE name = 'waitlist.notification_emails'");
    }
}
