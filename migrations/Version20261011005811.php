<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011005811 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the change fingerprints of the head to forge pull requests';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forge_pull_requests ADD change_fingerprint VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE forge_pull_requests ADD lines_fingerprint VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE forge_pull_requests ADD change_fingerprint_sha VARCHAR(64) DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forge_pull_requests DROP change_fingerprint');
        $this->addSql('ALTER TABLE forge_pull_requests DROP lines_fingerprint');
        $this->addSql('ALTER TABLE forge_pull_requests DROP change_fingerprint_sha');
    }
}
