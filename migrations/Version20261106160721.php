<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106160721 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the insights public link and public series label tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE insights_public_links (id UUID NOT NULL, kind VARCHAR(16) NOT NULL, code VARCHAR(12) NOT NULL, experiment VARCHAR(64) DEFAULT NULL, enabled BOOLEAN NOT NULL, note TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, project_id UUID NOT NULL, saved_graph_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1CA041FD77153098 ON insights_public_links (code)');
        $this->addSql('CREATE INDEX IDX_1CA041FD166D1F9C ON insights_public_links (project_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1CA041FDF7F24F1F ON insights_public_links (saved_graph_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_insights_public_link_experiment ON insights_public_links (project_id, experiment)');
        $this->addSql('CREATE TABLE insights_public_series_labels (id UUID NOT NULL, kind VARCHAR(16) NOT NULL, key VARCHAR(255) NOT NULL, number INT NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_45D3E469166D1F9C ON insights_public_series_labels (project_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_insights_public_series_label ON insights_public_series_labels (project_id, kind, key)');
        $this->addSql('ALTER TABLE insights_public_links ADD CONSTRAINT FK_1CA041FD166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE insights_public_links ADD CONSTRAINT FK_1CA041FDF7F24F1F FOREIGN KEY (saved_graph_id) REFERENCES insights_saved_graphs (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE insights_public_series_labels ADD CONSTRAINT FK_45D3E469166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql(<<<'SQL'
            ALTER TABLE insights_public_links ADD CONSTRAINT chk_insights_public_link_target CHECK (
                (kind = 'graph' AND saved_graph_id IS NOT NULL AND experiment IS NULL)
                OR (kind = 'experiment' AND experiment IS NOT NULL AND saved_graph_id IS NULL)
            )
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE insights_public_links');
        $this->addSql('DROP TABLE insights_public_series_labels');
    }
}
