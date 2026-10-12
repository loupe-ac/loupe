<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011213001 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the artifacts table';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE artifacts (id UUID NOT NULL, archived_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, name VARCHAR(255) NOT NULL, source_link TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, project_id UUID NOT NULL, created_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_299E4688166D1F9C ON artifacts (project_id)');
        $this->addSql('CREATE INDEX IDX_299E4688B03A8386 ON artifacts (created_by_id)');
        $this->addSql('ALTER TABLE artifacts ADD CONSTRAINT FK_299E4688166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE artifacts ADD CONSTRAINT FK_299E4688B03A8386 FOREIGN KEY (created_by_id) REFERENCES users (id) NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE artifacts');
    }
}
