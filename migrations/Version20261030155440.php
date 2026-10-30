<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030155440 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the card values table';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE board_card_values (id UUID NOT NULL, key VARCHAR(64) NOT NULL, value JSONB NOT NULL, writer_kind VARCHAR(20) NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, card_id UUID NOT NULL, writer_user_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_8AE3EEE14ACC9A20 ON board_card_values (card_id)');
        $this->addSql('CREATE INDEX IDX_8AE3EEE1202E1783 ON board_card_values (writer_user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_board_card_values_card_key ON board_card_values (card_id, key)');
        $this->addSql('ALTER TABLE board_card_values ADD CONSTRAINT FK_8AE3EEE14ACC9A20 FOREIGN KEY (card_id) REFERENCES board_cards (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE board_card_values ADD CONSTRAINT FK_8AE3EEE1202E1783 FOREIGN KEY (writer_user_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_card_values DROP CONSTRAINT FK_8AE3EEE14ACC9A20');
        $this->addSql('ALTER TABLE board_card_values DROP CONSTRAINT FK_8AE3EEE1202E1783');
        $this->addSql('DROP TABLE board_card_values');
    }
}
