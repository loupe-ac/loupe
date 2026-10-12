<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012163500 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the newest approval of a pull request: its time, review id and approver login';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forge_pull_requests ADD latest_approved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE forge_pull_requests ADD latest_approval_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE forge_pull_requests ADD latest_approver_login VARCHAR(255) DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forge_pull_requests DROP latest_approved_at');
        $this->addSql('ALTER TABLE forge_pull_requests DROP latest_approval_id');
        $this->addSql('ALTER TABLE forge_pull_requests DROP latest_approver_login');
    }
}
