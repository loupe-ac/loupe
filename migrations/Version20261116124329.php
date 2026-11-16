<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261116124329 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Drop github_installations.project_id and list_incomplete, which no code reads any more; down() adds them back empty';
    }

    public function up(Schema $schema): void
    {
        // @contract-phase: the shared-installation release stopped every read and write of this column, and production runs it.
        $this->addSql('ALTER TABLE github_installations DROP CONSTRAINT FK_B577C4D3166D1F9C');
        // @contract-phase: the shared-installation release stopped every read and write of this column, and production runs it.
        $this->addSql('DROP INDEX IDX_B577C4D3166D1F9C');
        // @contract-phase: the shared-installation release stopped every read and write of this column, and production runs it.
        $this->addSql('ALTER TABLE github_installations DROP project_id');
        // @contract-phase: the shared-installation release stopped every read and write of this column, and production runs it.
        $this->addSql('ALTER TABLE github_installations DROP list_incomplete');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // The dropped data cannot return: the columns come back empty.
        $this->addSql('ALTER TABLE github_installations ADD list_incomplete BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE github_installations ADD project_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_B577C4D3166D1F9C ON github_installations (project_id)');
        $this->addSql('ALTER TABLE github_installations ADD CONSTRAINT FK_B577C4D3166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE SET NULL NOT DEFERRABLE');
    }
}
