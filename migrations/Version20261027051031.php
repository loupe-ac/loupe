<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261027051031 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the base ruleset mismatch flags to forge_pull_requests';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forge_pull_requests ADD base_requires_review BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql('ALTER TABLE forge_pull_requests ADD approval_check_unpinned BOOLEAN DEFAULT FALSE NOT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forge_pull_requests DROP base_requires_review');
        $this->addSql('ALTER TABLE forge_pull_requests DROP approval_check_unpinned');
    }
}
