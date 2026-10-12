<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011213002 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the artifact_versions table';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE artifact_versions (id UUID NOT NULL, number INT NOT NULL, stored_by_session VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, artifact_id UUID NOT NULL, stored_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_31A1D50FE28B07AC ON artifact_versions (artifact_id)');
        $this->addSql('CREATE INDEX IDX_31A1D50FEEACABA6 ON artifact_versions (stored_by_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_artifact_version_number ON artifact_versions (artifact_id, number)');
        $this->addSql('ALTER TABLE artifact_versions ADD CONSTRAINT FK_31A1D50FE28B07AC FOREIGN KEY (artifact_id) REFERENCES artifacts (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE artifact_versions ADD CONSTRAINT FK_31A1D50FEEACABA6 FOREIGN KEY (stored_by_id) REFERENCES users (id) NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE artifact_versions');
    }
}
