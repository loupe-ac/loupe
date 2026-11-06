<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106005406 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Store the API key and the dedupe key of an inbox item';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inbox_items ADD api_key_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE inbox_items ADD api_key_name VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE inbox_items ADD dedupe_key VARCHAR(200) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_inbox_item_open_dedupe_key ON inbox_items (project_id, dedupe_key) WHERE ((state)::text = \'open\'::text)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_inbox_item_open_dedupe_key');
        $this->addSql('ALTER TABLE inbox_items DROP api_key_id');
        $this->addSql('ALTER TABLE inbox_items DROP api_key_name');
        $this->addSql('ALTER TABLE inbox_items DROP dedupe_key');
    }
}
