<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261031191527 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Drop the unread card search indexes; the two columns wait for the next release';
    }

    /**
     * The previous image still writes board_cards.search_vector and selects both
     * columns, so they stay until a release that no longer maps them is live.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_board_cards_search_vector');
        $this->addSql('DROP INDEX idx_board_cards_project_search_language');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_board_cards_search_vector ON board_cards USING gin (search_vector)');
        $this->addSql('CREATE INDEX idx_board_cards_project_search_language ON board_cards (project_id, search_language)');
    }
}
