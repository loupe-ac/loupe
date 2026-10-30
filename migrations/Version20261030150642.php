<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030150642 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Seed the workflow.merge_queued_evaluations flag off on an instance that upgrades';
    }

    /**
     * An instance with a human account has run /install, so the installer seeds nothing more
     * and an old worker may still run during the deploy. The operator switches the flag on
     * once the web and worker containers run this build. A fresh install has no human account
     * yet, so no row is written and /install seeds the flag on.
     *
     * The two ids are User::SYSTEM_IDS.
     */
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO feature_flag (name, type, value, tags, options)
            SELECT 'workflow.merge_queued_evaluations', 'bool', 'false', '[]', NULL
            WHERE NOT EXISTS (
                SELECT 1 FROM feature_flag WHERE name = 'workflow.merge_queued_evaluations'
            )
            AND EXISTS (
                SELECT 1 FROM users
                WHERE id NOT IN ('1073e0a5-9b1c-42f7-8e44-a10a6e57c3d9', '626ccf14-9fe6-4ea9-a2a2-283445c43e7e')
            )
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM feature_flag WHERE name = 'workflow.merge_queued_evaluations'");
    }
}
