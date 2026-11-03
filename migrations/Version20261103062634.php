<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261103062634 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the skills a bridge reported to the bridges table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridges ADD skills JSON DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridges DROP skills');
    }
}
