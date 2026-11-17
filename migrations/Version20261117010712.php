<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261117010712 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create oauth_worker_run_credentials, the run record of each worker access token';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE oauth_worker_run_credentials (access_token_id VARCHAR(80) NOT NULL, bridge_id UUID NOT NULL, run_key UUID NOT NULL, session_id UUID NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, issued_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, user_id UUID NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (access_token_id))');
        $this->addSql('CREATE INDEX IDX_25C27C45A76ED395 ON oauth_worker_run_credentials (user_id)');
        $this->addSql('CREATE INDEX IDX_25C27C45166D1F9C ON oauth_worker_run_credentials (project_id)');
        $this->addSql('CREATE INDEX IDX_25C27C45166D1F9C4948DF557DC9266D ON oauth_worker_run_credentials (project_id, bridge_id, run_key)');
        $this->addSql('CREATE INDEX IDX_25C27C45613FECDF ON oauth_worker_run_credentials (session_id)');
        $this->addSql('CREATE INDEX IDX_25C27C45F9D83E2 ON oauth_worker_run_credentials (expires_at)');
        $this->addSql('ALTER TABLE oauth_worker_run_credentials ADD CONSTRAINT FK_25C27C45A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE oauth_worker_run_credentials ADD CONSTRAINT FK_25C27C45166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE oauth_worker_run_credentials DROP CONSTRAINT FK_25C27C45A76ED395');
        $this->addSql('ALTER TABLE oauth_worker_run_credentials DROP CONSTRAINT FK_25C27C45166D1F9C');
        $this->addSql('DROP TABLE oauth_worker_run_credentials');
    }
}
