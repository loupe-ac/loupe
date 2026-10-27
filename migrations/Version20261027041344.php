<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261027041344 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the approval check run, commit and conclusion to forge pull requests';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forge_pull_requests ADD approval_check_run_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE forge_pull_requests ADD approval_check_sha VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE forge_pull_requests ADD approval_check_conclusion VARCHAR(32) DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forge_pull_requests DROP approval_check_run_id');
        $this->addSql('ALTER TABLE forge_pull_requests DROP approval_check_sha');
        $this->addSql('ALTER TABLE forge_pull_requests DROP approval_check_conclusion');
    }
}
