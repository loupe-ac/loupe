<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261029075806 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Drop the App approval columns and the two unused ruleset flags from forge pull requests';
    }

    public function up(Schema $schema): void
    {
        // @contract-phase: the approval check replaced the App review, so no code reads or writes the column, and production runs that code.
        $this->addSql('ALTER TABLE forge_pull_requests DROP app_approval_id');
        // @contract-phase: the approval check replaced the App review, so no code reads or writes the column, and production runs that code.
        $this->addSql('ALTER TABLE forge_pull_requests DROP app_approval_sha');
        // @contract-phase: the approval check replaced the App review, so no code reads or writes the column, and production runs that code.
        $this->addSql('ALTER TABLE forge_pull_requests DROP base_requires_review');
        // @contract-phase: the approval check replaced the App review, so no code reads or writes the column, and production runs that code.
        $this->addSql('ALTER TABLE forge_pull_requests DROP approval_check_unpinned');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forge_pull_requests ADD app_approval_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE forge_pull_requests ADD app_approval_sha VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE forge_pull_requests ADD base_requires_review BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql('ALTER TABLE forge_pull_requests ADD approval_check_unpinned BOOLEAN DEFAULT FALSE NOT NULL');
    }
}
