<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110171229 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Free github_installations from its project (nullable project_id, kept until a later migration drops it), add the account id and the repository access_lost_at date';
    }

    public function up(Schema $schema): void
    {
        // The old app image still maps project_id and list_incomplete during the rolling deploy, so both columns stay.
        // Deleting a project must no longer delete a shared installation, so the old cascade becomes SET NULL.
        $this->addSql('ALTER TABLE github_installations DROP CONSTRAINT FK_B577C4D3166D1F9C');
        $this->addSql('ALTER TABLE github_installations ALTER project_id DROP NOT NULL');
        $this->addSql('ALTER TABLE github_installations ADD CONSTRAINT FK_B577C4D3166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE github_installations ADD account_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE forge_repositories ADD access_lost_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // An installation with no project cannot go back, so the rollback deletes it.
        $this->addSql('ALTER TABLE forge_repositories DROP access_lost_at');
        $this->addSql('ALTER TABLE github_installations DROP account_id');
        $this->addSql('DELETE FROM github_installations WHERE project_id IS NULL');
        $this->addSql('ALTER TABLE github_installations DROP CONSTRAINT FK_B577C4D3166D1F9C');
        $this->addSql('ALTER TABLE github_installations ALTER project_id SET NOT NULL');
        $this->addSql('ALTER TABLE github_installations ADD CONSTRAINT FK_B577C4D3166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE');
    }
}
