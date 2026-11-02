<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261102002955 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the table of API idempotency keys';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE api_idempotency_records (id UUID NOT NULL, credential VARCHAR(255) NOT NULL, idempotency_key VARCHAR(255) NOT NULL, owner_id UUID NOT NULL, request_hash VARCHAR(64) NOT NULL, state VARCHAR(20) NOT NULL, status INT DEFAULT NULL, headers JSON DEFAULT NULL, body TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_api_idempotency_expires_at ON api_idempotency_records (expires_at)');
        $this->addSql('CREATE INDEX idx_api_idempotency_owner ON api_idempotency_records (owner_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_api_idempotency_credential_key ON api_idempotency_records (credential, idempotency_key)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE api_idempotency_records');
    }
}
