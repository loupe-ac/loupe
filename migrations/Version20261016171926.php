<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016171926 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the target bridge, the branch and the parameters of a command run to work_requests';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE work_requests ADD target_bridge_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE work_requests ADD branch VARCHAR(200) DEFAULT NULL');
        $this->addSql('ALTER TABLE work_requests ADD params JSON DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE work_requests DROP target_bridge_id');
        $this->addSql('ALTER TABLE work_requests DROP branch');
        $this->addSql('ALTER TABLE work_requests DROP params');
    }
}
