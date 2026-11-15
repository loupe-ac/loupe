<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261115130000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Keep card creation, update and completion times to the microsecond';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        // @contract-phase: the precision only widens, so the previous image writes whole seconds and reads them back as before.
        $this->addSql('ALTER TABLE board_cards ALTER created_at TYPE TIMESTAMP(6) WITHOUT TIME ZONE');
        // @contract-phase: the precision only widens, so the previous image writes whole seconds and reads them back as before.
        $this->addSql('ALTER TABLE board_cards ALTER updated_at TYPE TIMESTAMP(6) WITHOUT TIME ZONE');
        // @contract-phase: the precision only widens, so the previous image writes whole seconds and reads them back as before.
        $this->addSql('ALTER TABLE board_cards ALTER completed_at TYPE TIMESTAMP(6) WITHOUT TIME ZONE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_cards ALTER created_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE board_cards ALTER updated_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE board_cards ALTER completed_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
    }
}
