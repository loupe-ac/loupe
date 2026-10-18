<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261017140512 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the inbox ask that a worker run waits on to bridge_worker_runs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_worker_runs ADD waits_on_ask_id UUID DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_worker_runs DROP waits_on_ask_id');
    }
}
