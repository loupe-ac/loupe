<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012154230 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add notes to artifact_versions';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE artifact_versions ADD notes TEXT DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE artifact_versions DROP notes');
    }
}
