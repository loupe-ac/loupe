<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022170930 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add invite_claimed_at to waitlist_entries';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE waitlist_entries ADD invite_claimed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE waitlist_entries DROP invite_claimed_at');
    }
}
