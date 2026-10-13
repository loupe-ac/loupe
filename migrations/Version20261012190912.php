<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012190912 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the picks and the free text of the Notify me form to waitlist entries';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE waitlist_entries ADD picks JSONB DEFAULT '[]' NOT NULL, ADD other_pick VARCHAR(200) DEFAULT NULL");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE waitlist_entries DROP picks, DROP other_pick');
    }
}
