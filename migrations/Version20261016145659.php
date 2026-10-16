<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016145659 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the fallback merge runs that each epic awaits to board cards';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE board_cards ADD epic_branch_pending_syncs JSONB DEFAULT '{}' NOT NULL");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_cards DROP epic_branch_pending_syncs');
    }
}
