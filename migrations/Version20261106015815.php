<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106015815 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Let the owner dismiss every notice';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE inbox_items SET dismissible = true WHERE kind = 'notice' AND dismissible = false");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE inbox_items SET dismissible = false WHERE kind = 'notice' AND state = 'open'");
    }
}
