<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018110858 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the repositories that a bridge reports, with their report time, to bridges';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridges ADD repositories JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE bridges ADD repositories_reported_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridges DROP repositories');
        $this->addSql('ALTER TABLE bridges DROP repositories_reported_at');
    }
}
