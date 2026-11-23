<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261123221304 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the label and the deadline to inbox items';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inbox_items ADD label VARCHAR(40) DEFAULT NULL');
        $this->addSql('ALTER TABLE inbox_items ADD defaults_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_inbox_items_defaults_at ON inbox_items (defaults_at) WHERE (defaults_at IS NOT NULL)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_inbox_items_defaults_at');
        $this->addSql('ALTER TABLE inbox_items DROP label');
        $this->addSql('ALTER TABLE inbox_items DROP defaults_at');
    }
}
