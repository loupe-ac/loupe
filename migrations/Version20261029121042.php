<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261029121042 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add approval_check_passed_at to forge pull requests';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forge_pull_requests ADD approval_check_passed_at TIMESTAMP(6) WITHOUT TIME ZONE DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forge_pull_requests DROP approval_check_passed_at');
    }
}
