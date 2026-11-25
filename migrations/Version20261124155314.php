<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124155314 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the table of the last time each person opened the Decisions tab of a card';
    }

    public function up(Schema $schema): void
    {
        // @contract-phase: widening the timestamp precision keeps every old value, and the old image reads and writes it as before
        $this->addSql('ALTER TABLE inbox_decisions ALTER created_at TYPE TIMESTAMP(6) WITHOUT TIME ZONE');
        $this->addSql('CREATE TABLE inbox_decision_seen (id UUID NOT NULL, seen_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, project_id UUID NOT NULL, user_id UUID NOT NULL, card_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_D5B9BF88166D1F9C ON inbox_decision_seen (project_id)');
        $this->addSql('CREATE INDEX IDX_D5B9BF88A76ED395 ON inbox_decision_seen (user_id)');
        $this->addSql('CREATE INDEX IDX_D5B9BF884ACC9A20 ON inbox_decision_seen (card_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_inbox_decision_seen_user_card ON inbox_decision_seen (user_id, card_id)');
        $this->addSql('ALTER TABLE inbox_decision_seen ADD CONSTRAINT FK_D5B9BF88166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE inbox_decision_seen ADD CONSTRAINT FK_D5B9BF88A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE inbox_decision_seen ADD CONSTRAINT FK_D5B9BF884ACC9A20 FOREIGN KEY (card_id) REFERENCES board_cards (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inbox_decision_seen DROP CONSTRAINT FK_D5B9BF88166D1F9C');
        $this->addSql('ALTER TABLE inbox_decision_seen DROP CONSTRAINT FK_D5B9BF88A76ED395');
        $this->addSql('ALTER TABLE inbox_decision_seen DROP CONSTRAINT FK_D5B9BF884ACC9A20');
        $this->addSql('DROP TABLE inbox_decision_seen');
        $this->addSql('ALTER TABLE inbox_decisions ALTER created_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
    }
}
