<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026122627 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Store the human review switch and its seen artifacts on the card';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_cards ADD requires_human_review BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE board_cards ADD human_review_seen_artifacts JSONB DEFAULT \'[]\' NOT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_cards DROP requires_human_review');
        $this->addSql('ALTER TABLE board_cards DROP human_review_seen_artifacts');
    }
}
