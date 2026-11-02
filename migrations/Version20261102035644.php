<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261102035644 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the API key id and name to card history rows';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_card_events ADD api_key_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE board_card_events ADD api_key_name VARCHAR(100) DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_card_events DROP api_key_id');
        $this->addSql('ALTER TABLE board_card_events DROP api_key_name');
    }
}
