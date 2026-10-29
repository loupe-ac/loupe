<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261029031500 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the time with subagents and the call count to the bucket times of a worker run';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_worker_run_bucket_times ADD ms_with_subagents BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE bridge_worker_run_bucket_times ADD calls INT DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_worker_run_bucket_times DROP ms_with_subagents');
        $this->addSql('ALTER TABLE bridge_worker_run_bucket_times DROP calls');
    }
}
