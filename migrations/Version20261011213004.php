<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011213004 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the artifact_files table';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE artifact_files (id UUID NOT NULL, path VARCHAR(1024) NOT NULL, content_type VARCHAR(255) NOT NULL, version_id UUID NOT NULL, blob_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_FA7973B74BBC2705 ON artifact_files (version_id)');
        $this->addSql('CREATE INDEX IDX_FA7973B7ED3E8EA5 ON artifact_files (blob_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_artifact_file_path ON artifact_files (version_id, path)');
        $this->addSql('ALTER TABLE artifact_files ADD CONSTRAINT FK_FA7973B74BBC2705 FOREIGN KEY (version_id) REFERENCES artifact_versions (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE artifact_files ADD CONSTRAINT FK_FA7973B7ED3E8EA5 FOREIGN KEY (blob_id) REFERENCES artifact_blobs (id) NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE artifact_files');
    }
}
