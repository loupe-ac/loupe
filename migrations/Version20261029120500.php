<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261029120500 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Drop the two ruleset mismatch flags from forge_pull_requests';
    }

    public function up(Schema $schema): void
    {
        // @contract-phase: the previous release removed every read and write of the column, and production runs it.
        $this->addSql('ALTER TABLE forge_pull_requests DROP base_requires_review');
        // @contract-phase: the previous release removed every read and write of the column, and production runs it.
        $this->addSql('ALTER TABLE forge_pull_requests DROP approval_check_unpinned');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forge_pull_requests ADD base_requires_review BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql('ALTER TABLE forge_pull_requests ADD approval_check_unpinned BOOLEAN DEFAULT FALSE NOT NULL');
    }
}
