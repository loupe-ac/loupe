<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261107192015 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the table of kept insights results and the table of the lock store';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE insights_kept_results (id UUID NOT NULL, experiment VARCHAR(255) DEFAULT NULL, settings_hash VARCHAR(64) NOT NULL, computed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, payload TEXT NOT NULL, project_id UUID NOT NULL, saved_graph_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_EAD4A6B7166D1F9C ON insights_kept_results (project_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_insights_kept_result_graph ON insights_kept_results (saved_graph_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_insights_kept_result_experiment ON insights_kept_results (project_id, experiment)');
        $this->addSql('CREATE TABLE lock_keys (key_id VARCHAR(64) NOT NULL, key_token VARCHAR(44) NOT NULL, key_expiration INT NOT NULL, PRIMARY KEY (key_id))');
        $this->addSql('ALTER TABLE insights_kept_results ADD CONSTRAINT FK_EAD4A6B7166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE insights_kept_results ADD CONSTRAINT FK_EAD4A6B7F7F24F1F FOREIGN KEY (saved_graph_id) REFERENCES insights_saved_graphs (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE insights_kept_results');
        $this->addSql('DROP TABLE lock_keys');
    }
}
