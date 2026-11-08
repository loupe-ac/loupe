<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261108000624 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the insights_project_activity table that tells the sweep which projects are awake';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE insights_project_activity (viewed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, data_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, project_id UUID NOT NULL, PRIMARY KEY (project_id))');
        $this->addSql('ALTER TABLE insights_project_activity ADD CONSTRAINT FK_B639C087166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE insights_project_activity DROP CONSTRAINT FK_B639C087166D1F9C');
        $this->addSql('DROP TABLE insights_project_activity');
    }
}
