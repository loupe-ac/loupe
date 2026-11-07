<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261107052310 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Store merge-approval agent for each repository that an active GitHub installation of its project gave it';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO workflow_repository_settings (id, repository_id, setting_key, value, project_id)
            SELECT gen_random_uuid(), r.id, 'merge-approval', '"agent"', r.project_id
            FROM forge_repositories r
            WHERE r.source = 'installation'
              AND EXISTS (
                  SELECT 1 FROM github_installations i
                  WHERE i.project_id = r.project_id
                    AND CAST(i.installation_id AS TEXT) = r.source_ref
                    AND i.removed_at IS NULL
                    AND i.suspended_at IS NULL
              )
            ON CONFLICT (repository_id, setting_key) DO NOTHING
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM workflow_repository_settings WHERE setting_key = \'merge-approval\' AND value = \'"agent"\'');
    }
}
