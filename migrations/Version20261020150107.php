<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261020150107 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Mark every posted live review check stale, so the sweep renames it on its run';
    }

    public function up(Schema $schema): void
    {
        // A posted check keeps the name it had, and a check with no digest posts again on the same run.
        $this->addSql('UPDATE board_site_review_check_states SET notes_digest = NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The cleared digests are gone.');
    }
}
