<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261029210232 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Flag the pull request links whose link time cannot be trusted';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_card_pull_requests ADD predates_link_times BOOLEAN DEFAULT true NOT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_card_pull_requests DROP predates_link_times');
    }
}
