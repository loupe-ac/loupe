<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261125133125 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Link an inbox review item to the discovery run whose report it waits on';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inbox_items ADD discovery_run_id UUID DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inbox_items DROP discovery_run_id');
    }
}
