<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261117002945 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Record the forge user who linked each installation repository';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forge_repositories ADD claimant_account_id VARCHAR(255) DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forge_repositories DROP claimant_account_id');
    }
}
