<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110192500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the waitlist block patterns table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE waitlist_block_patterns (id UUID NOT NULL, like_pattern VARCHAR(400) NOT NULL, pattern VARCHAR(180) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_E71FE7B2A3BCFC8E ON waitlist_block_patterns (pattern)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE waitlist_block_patterns');
    }
}
