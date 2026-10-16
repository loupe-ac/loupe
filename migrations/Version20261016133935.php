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
        return 'Add the time of the last merge of the default branch into the epic branch to board cards';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_cards ADD epic_branch_synced_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_cards DROP epic_branch_synced_at');
    }
}
