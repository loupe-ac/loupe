<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261107214405 extends AbstractMigration
{
    private const array TABLES = ['bridge_worker_runs', 'bridge_worker_run_facts'];

    #[\Override]
    public function getDescription(): string
    {
        return 'Add the five wake columns to the worker runs and to their fact rows';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            $this->addSql(\sprintf('ALTER TABLE %s ADD wake_trigger VARCHAR(20) DEFAULT NULL', $table));
            $this->addSql(\sprintf('ALTER TABLE %s ADD wake_gap_ms BIGINT DEFAULT NULL', $table));
            $this->addSql(\sprintf('ALTER TABLE %s ADD wake_first_input_tokens BIGINT DEFAULT NULL', $table));
            $this->addSql(\sprintf('ALTER TABLE %s ADD wake_first_cache_read_tokens BIGINT DEFAULT NULL', $table));
            $this->addSql(\sprintf('ALTER TABLE %s ADD wake_first_cache_write_tokens BIGINT DEFAULT NULL', $table));
        }
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            $this->addSql(\sprintf('ALTER TABLE %s DROP wake_trigger, DROP wake_gap_ms, DROP wake_first_input_tokens, DROP wake_first_cache_read_tokens, DROP wake_first_cache_write_tokens', $table));
        }
    }
}
