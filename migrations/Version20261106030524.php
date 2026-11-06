<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106030524 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the table of manual experiment exclusions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bridge_experiment_exclusions (id UUID NOT NULL, card_id UUID NOT NULL, experiment VARCHAR(64) NOT NULL, reason VARCHAR(500) NOT NULL, excluded_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, project_id UUID NOT NULL, excluded_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_27CB890D166D1F9C ON bridge_experiment_exclusions (project_id)');
        $this->addSql('CREATE INDEX IDX_27CB890D5B30B80B ON bridge_experiment_exclusions (excluded_by_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_bridge_experiment_exclusion ON bridge_experiment_exclusions (project_id, experiment, card_id)');
        $this->addSql('ALTER TABLE bridge_experiment_exclusions ADD CONSTRAINT FK_27CB890D166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE bridge_experiment_exclusions ADD CONSTRAINT FK_27CB890D5B30B80B FOREIGN KEY (excluded_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_experiment_exclusions DROP CONSTRAINT FK_27CB890D166D1F9C');
        $this->addSql('ALTER TABLE bridge_experiment_exclusions DROP CONSTRAINT FK_27CB890D5B30B80B');
        $this->addSql('DROP TABLE bridge_experiment_exclusions');
    }
}
