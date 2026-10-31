<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030225041 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the insights saved graph table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE insights_saved_graphs (id UUID NOT NULL, name VARCHAR(120) NOT NULL, metric VARCHAR(140) NOT NULL, unit VARCHAR(16) NOT NULL, statistic VARCHAR(16) NOT NULL, metric_group VARCHAR(32) NOT NULL, range VARCHAR(16) NOT NULL, bucket VARCHAR(16) NOT NULL, compare VARCHAR(16) NOT NULL, chart_type VARCHAR(32) NOT NULL, width VARCHAR(8) NOT NULL, show_legend BOOLEAN NOT NULL, position INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, removed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_CCE9300F166D1F9C ON insights_saved_graphs (project_id)');
        $this->addSql('CREATE INDEX idx_insights_saved_graph_position ON insights_saved_graphs (project_id, position)');
        $this->addSql('ALTER TABLE insights_saved_graphs ADD CONSTRAINT FK_CCE9300F166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE insights_saved_graphs');
    }
}
