<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016133935 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the blockers that the last merge of the default branch brought into the epic branch to board cards';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE board_cards ADD epic_branch_synced_blockers JSONB DEFAULT '{}' NOT NULL");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_cards DROP epic_branch_synced_blockers');
    }
}
