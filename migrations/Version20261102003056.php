<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261102003056 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the api_keys table';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE api_keys (id UUID NOT NULL, last_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, expiry_notice_sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_notice_sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, name VARCHAR(100) NOT NULL, key_hash VARCHAR(64) NOT NULL, display_start VARCHAR(32) NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, owner_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9579321F57BFB971 ON api_keys (key_hash)');
        $this->addSql('CREATE INDEX IDX_9579321F7E3C61F9 ON api_keys (owner_id)');
        $this->addSql('ALTER TABLE api_keys ADD CONSTRAINT FK_9579321F7E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE api_keys DROP CONSTRAINT FK_9579321F7E3C61F9');
        $this->addSql('DROP TABLE api_keys');
    }
}
