<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261025013137 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the push_devices table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE push_devices (id UUID NOT NULL, last_delivered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, stopped_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, warning_dismissed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, endpoint TEXT NOT NULL, public_key VARCHAR(255) NOT NULL, auth_token VARCHAR(255) NOT NULL, content_encoding VARCHAR(20) NOT NULL, label VARCHAR(80) NOT NULL, device_token_hash VARCHAR(64) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, owner_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2A42D483C4420F7B ON push_devices (endpoint)');
        $this->addSql('CREATE INDEX IDX_2A42D4837E3C61F9 ON push_devices (owner_id)');
        $this->addSql('ALTER TABLE push_devices ADD CONSTRAINT FK_2A42D4837E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE push_devices DROP CONSTRAINT FK_2A42D4837E3C61F9');
        $this->addSql('DROP TABLE push_devices');
    }
}
