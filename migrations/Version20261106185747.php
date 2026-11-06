<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106185747 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the table of public link takedowns';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE insights_public_link_takedowns (id UUID NOT NULL, taken_down_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, project_id UUID NOT NULL, taken_down_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_EE49A68166D1F9C ON insights_public_link_takedowns (project_id)');
        $this->addSql('CREATE INDEX IDX_EE49A6851E249C8 ON insights_public_link_takedowns (taken_down_by_id)');
        $this->addSql('ALTER TABLE insights_public_link_takedowns ADD CONSTRAINT FK_EE49A68166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE insights_public_link_takedowns ADD CONSTRAINT FK_EE49A6851E249C8 FOREIGN KEY (taken_down_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE insights_public_link_takedowns DROP CONSTRAINT FK_EE49A68166D1F9C');
        $this->addSql('ALTER TABLE insights_public_link_takedowns DROP CONSTRAINT FK_EE49A6851E249C8');
        $this->addSql('DROP TABLE insights_public_link_takedowns');
    }
}
