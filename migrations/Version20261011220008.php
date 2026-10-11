<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011220008 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the table that links artifacts to cards';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE board_card_artifacts (id UUID NOT NULL, linked_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, card_id UUID NOT NULL, artifact_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_C1510B5F4ACC9A20 ON board_card_artifacts (card_id)');
        $this->addSql('CREATE INDEX IDX_C1510B5FE28B07AC ON board_card_artifacts (artifact_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_board_card_artifact ON board_card_artifacts (card_id, artifact_id)');
        $this->addSql('ALTER TABLE board_card_artifacts ADD CONSTRAINT FK_C1510B5F4ACC9A20 FOREIGN KEY (card_id) REFERENCES board_cards (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE board_card_artifacts ADD CONSTRAINT FK_C1510B5FE28B07AC FOREIGN KEY (artifact_id) REFERENCES artifacts (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_card_artifacts DROP CONSTRAINT FK_C1510B5F4ACC9A20');
        $this->addSql('ALTER TABLE board_card_artifacts DROP CONSTRAINT FK_C1510B5FE28B07AC');
        $this->addSql('DROP TABLE board_card_artifacts');
    }
}
