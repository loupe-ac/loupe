<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261107045102 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the warning lines of the workflow file read to the forge repositories';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forge_repositories ADD file_warnings JSON DEFAULT \'[]\' NOT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forge_repositories DROP file_warnings');
    }
}
