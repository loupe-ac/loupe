<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016181007 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the table that ties a bridge outage notice to its project';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE inbox_bridge_outages (id UUID NOT NULL, ended_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, project_id UUID NOT NULL, item_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_7863D909166D1F9C ON inbox_bridge_outages (project_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_7863D909126F525E ON inbox_bridge_outages (item_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_inbox_bridge_outages_open_project ON inbox_bridge_outages (project_id) WHERE (ended_at IS NULL)');
        $this->addSql('ALTER TABLE inbox_bridge_outages ADD CONSTRAINT FK_7863D909166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE inbox_bridge_outages ADD CONSTRAINT FK_7863D909126F525E FOREIGN KEY (item_id) REFERENCES inbox_items (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inbox_bridge_outages DROP CONSTRAINT FK_7863D909166D1F9C');
        $this->addSql('ALTER TABLE inbox_bridge_outages DROP CONSTRAINT FK_7863D909126F525E');
        $this->addSql('DROP TABLE inbox_bridge_outages');
    }
}
