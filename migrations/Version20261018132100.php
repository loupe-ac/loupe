<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018132100 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add projects.git_identity_managed, on for every project';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE projects ADD git_identity_managed BOOLEAN DEFAULT true NOT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE projects DROP git_identity_managed');
    }
}
