<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026170833 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Store the approval of the GitHub App apart from a person\'s approval on forge pull requests';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forge_pull_requests ADD app_approval_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE forge_pull_requests ADD app_approval_sha VARCHAR(64) DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forge_pull_requests DROP app_approval_id');
        $this->addSql('ALTER TABLE forge_pull_requests DROP app_approval_sha');
    }
}
