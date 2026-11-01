<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261031001323 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the OpenRouter management key table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE openrouter_keys (id UUID NOT NULL, last_four VARCHAR(4) NOT NULL, state VARCHAR(20) DEFAULT \'working\' NOT NULL, refused_since TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_read_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, secret TEXT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID DEFAULT NULL, project_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5DD441DAA76ED395 ON openrouter_keys (user_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5DD441DA166D1F9C ON openrouter_keys (project_id)');
        $this->addSql('ALTER TABLE openrouter_keys ADD CONSTRAINT FK_5DD441DAA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE openrouter_keys ADD CONSTRAINT FK_5DD441DA166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE openrouter_keys ADD CONSTRAINT openrouter_keys_one_owner CHECK ((user_id IS NULL) <> (project_id IS NULL))');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE openrouter_keys DROP CONSTRAINT FK_5DD441DAA76ED395');
        $this->addSql('ALTER TABLE openrouter_keys DROP CONSTRAINT FK_5DD441DA166D1F9C');
        $this->addSql('DROP TABLE openrouter_keys');
    }
}
