<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120041843 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Store the round of the rule on each work request';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE work_requests ADD round_number INT DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE work_requests DROP round_number');
    }
}
