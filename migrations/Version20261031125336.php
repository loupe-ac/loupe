<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261031125336 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the table that ties an inbox notice to a refused OpenRouter key';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE inbox_openrouter_key_notices (id UUID NOT NULL, ended_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, key_id UUID NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, project_id UUID NOT NULL, item_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_E18BF97E166D1F9C ON inbox_openrouter_key_notices (project_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_E18BF97E126F525E ON inbox_openrouter_key_notices (item_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_inbox_openrouter_key_notices_open ON inbox_openrouter_key_notices (key_id, project_id) WHERE (ended_at IS NULL)');
        $this->addSql('ALTER TABLE inbox_openrouter_key_notices ADD CONSTRAINT FK_E18BF97E166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE inbox_openrouter_key_notices ADD CONSTRAINT FK_E18BF97E126F525E FOREIGN KEY (item_id) REFERENCES inbox_items (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inbox_openrouter_key_notices DROP CONSTRAINT FK_E18BF97E166D1F9C');
        $this->addSql('ALTER TABLE inbox_openrouter_key_notices DROP CONSTRAINT FK_E18BF97E126F525E');
        $this->addSql('DROP TABLE inbox_openrouter_key_notices');
    }
}
