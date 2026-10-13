<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012232118 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the time the checks of a pull request began to run';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forge_pull_requests ADD checks_pending_since TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forge_pull_requests DROP checks_pending_since');
    }
}
