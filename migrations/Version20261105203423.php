<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261105203423 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Keep review times and card value write times to the microsecond';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        // @contract-phase: the precision only widens, so the previous image writes whole seconds and reads them back as before.
        $this->addSql('ALTER TABLE reviews ALTER submitted_at TYPE TIMESTAMP(6) WITHOUT TIME ZONE');
        // @contract-phase: the precision only widens, so the previous image writes whole seconds and reads them back as before.
        $this->addSql('ALTER TABLE board_card_values ALTER updated_at TYPE TIMESTAMP(6) WITHOUT TIME ZONE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reviews ALTER submitted_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE board_card_values ALTER updated_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
    }
}
