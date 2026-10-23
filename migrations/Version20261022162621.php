<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022162621 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Insert the singleton workflow user that authors the verdicts of workflow rules.';
    }

    public function up(Schema $schema): void
    {
        // Every value is a literal, as in the migration that inserts the agent user.
        // The runtime copy of the id is App\Module\Account\Entity\User::WORKFLOW_ID.
        // No password and no roles, and an IANA-reserved `.invalid` address: nothing can
        // authenticate as it. The conflict target is the id, so a clash on the email
        // aborts the migration instead of leaving the app with no workflow row.
        $this->addSql(<<<'SQL'
            INSERT INTO users (id, roles, full_name, email, password, created_at)
            VALUES ('626ccf14-9fe6-4ea9-a2a2-283445c43e7e', '[]', 'Workflow', 'workflow@loupe.invalid', NULL, now())
            ON CONFLICT (id) DO NOTHING
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // Rows that the account wrote reference this row, so they go first.
        $this->addSql("DELETE FROM inbox_reviews WHERE reviewer_id = '626ccf14-9fe6-4ea9-a2a2-283445c43e7e'");
        $this->addSql("DELETE FROM reviews WHERE reviewer_id = '626ccf14-9fe6-4ea9-a2a2-283445c43e7e'");
        $this->addSql("DELETE FROM users WHERE id = '626ccf14-9fe6-4ea9-a2a2-283445c43e7e'");
    }
}
