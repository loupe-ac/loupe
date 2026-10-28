<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261027174455 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create forge_repository_checks';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE forge_repository_checks (id UUID NOT NULL, checked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, attempted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, failure VARCHAR(50) DEFAULT NULL, items JSON NOT NULL, settings JSON DEFAULT NULL, next_read_ticket INT DEFAULT 0 NOT NULL, accepted_read_ticket INT DEFAULT 0 NOT NULL, repository_id UUID NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_56DED61750C9D4F7 ON forge_repository_checks (repository_id)');
        $this->addSql('CREATE INDEX IDX_56DED617166D1F9C ON forge_repository_checks (project_id)');
        $this->addSql('ALTER TABLE forge_repository_checks ADD CONSTRAINT FK_56DED61750C9D4F7 FOREIGN KEY (repository_id) REFERENCES forge_repositories (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE forge_repository_checks ADD CONSTRAINT FK_56DED617166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forge_repository_checks DROP CONSTRAINT FK_56DED61750C9D4F7');
        $this->addSql('ALTER TABLE forge_repository_checks DROP CONSTRAINT FK_56DED617166D1F9C');
        $this->addSql('DROP TABLE forge_repository_checks');
    }
}
