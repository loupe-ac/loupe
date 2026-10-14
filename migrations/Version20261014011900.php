<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014011900 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the suggested actions of an agent ask to inbox items';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inbox_items ADD suggested_actions JSON DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inbox_items DROP suggested_actions');
    }
}
