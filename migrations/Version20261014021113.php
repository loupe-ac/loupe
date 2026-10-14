<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014021113 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the answered wait to inbox actions';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inbox_actions ADD wait_id UUID DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inbox_actions DROP wait_id');
    }
}
