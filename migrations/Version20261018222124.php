<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018222124 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add responded_through_agent to inbox_items';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inbox_items ADD responded_through_agent BOOLEAN DEFAULT false NOT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inbox_items DROP responded_through_agent');
    }
}
