<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016153117 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the command entries and the worktrees that a bridge reports, with their report times, to bridges';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridges ADD command_entries JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE bridges ADD command_entries_reported_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE bridges ADD worktrees JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE bridges ADD worktrees_reported_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridges DROP command_entries');
        $this->addSql('ALTER TABLE bridges DROP command_entries_reported_at');
        $this->addSql('ALTER TABLE bridges DROP worktrees');
        $this->addSql('ALTER TABLE bridges DROP worktrees_reported_at');
    }
}
