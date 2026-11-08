<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261107160000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Record on each card whether it ever left the Backlog, and mark every card outside the Backlog or with a move in its history as started';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_cards ADD left_backlog BOOLEAN DEFAULT false NOT NULL');
        $this->addSql(<<<'SQL'
            UPDATE board_cards c
            SET left_backlog = true
            WHERE EXISTS (SELECT 1 FROM board_columns bc WHERE bc.id = c.column_id AND bc.is_default = false)
               OR EXISTS (SELECT 1 FROM board_card_events e WHERE e.card_id = c.id AND e.kind = 'moved')
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_cards DROP left_backlog');
    }
}
