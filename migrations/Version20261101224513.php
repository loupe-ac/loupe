<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101224513 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the card field that records a session move';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_cards ADD arrived_in_session BOOLEAN DEFAULT false NOT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_cards DROP arrived_in_session');
    }
}
