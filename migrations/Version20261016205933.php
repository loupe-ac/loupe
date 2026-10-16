<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016205933 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the table of the live output chunks of a worker run';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bridge_worker_run_output_chunks (id UUID NOT NULL, start_offset INT NOT NULL, length INT NOT NULL, text TEXT NOT NULL, cut BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, run_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_54427A9B84E3FEC4 ON bridge_worker_run_output_chunks (run_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_bridge_worker_run_output_chunk_start ON bridge_worker_run_output_chunks (run_id, start_offset)');
        $this->addSql('ALTER TABLE bridge_worker_run_output_chunks ADD CONSTRAINT FK_54427A9B84E3FEC4 FOREIGN KEY (run_id) REFERENCES bridge_worker_runs (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE bridge_worker_run_output_chunks');
    }
}
