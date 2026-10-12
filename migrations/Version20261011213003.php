<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011213003 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the artifact_blobs table';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE artifact_blobs (id UUID NOT NULL, sha256 CHAR(64) NOT NULL, bytes BYTEA NOT NULL, size INT NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_75200DDB166D1F9C ON artifact_blobs (project_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_artifact_blob_hash ON artifact_blobs (project_id, sha256)');
        $this->addSql('ALTER TABLE artifact_blobs ADD CONSTRAINT FK_75200DDB166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE artifact_blobs');
    }
}
