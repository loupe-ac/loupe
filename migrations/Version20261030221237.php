<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030221237 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Drop the two App approval columns from forge_pull_requests';
    }

    public function up(Schema $schema): void
    {
        // @contract-phase: the previous release removed every read and write of the column, and production runs it.
        $this->addSql('ALTER TABLE forge_pull_requests DROP app_approval_id');
        // @contract-phase: the previous release removed every read and write of the column, and production runs it.
        $this->addSql('ALTER TABLE forge_pull_requests DROP app_approval_sha');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forge_pull_requests ADD app_approval_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE forge_pull_requests ADD app_approval_sha VARCHAR(64) DEFAULT NULL');
    }
}
