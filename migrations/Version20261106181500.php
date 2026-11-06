<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106181500 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the commits a pull request head lacks from its base, and the head on which the forge refused a branch update for good';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forge_pull_requests ADD behind_by INT DEFAULT NULL');
        $this->addSql('ALTER TABLE forge_pull_requests ADD update_refused_sha VARCHAR(64) DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forge_pull_requests DROP behind_by');
        $this->addSql('ALTER TABLE forge_pull_requests DROP update_refused_sha');
    }
}
