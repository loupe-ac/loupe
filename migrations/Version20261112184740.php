<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112184740 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Record the API key on inbox closes, inbox actions, bridge commands and interactive run closes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inbox_items ADD closed_api_key_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE inbox_items ADD closed_api_key_name VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE inbox_actions ADD api_key_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE inbox_actions ADD api_key_name VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE bridge_commands ADD api_key_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE bridge_commands ADD api_key_name VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE bridge_commands ADD cancelled_api_key_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE bridge_commands ADD cancelled_api_key_name VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE bridge_worker_runs ADD closed_api_key_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE bridge_worker_runs ADD closed_api_key_name VARCHAR(100) DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_worker_runs DROP closed_api_key_name');
        $this->addSql('ALTER TABLE bridge_worker_runs DROP closed_api_key_id');
        $this->addSql('ALTER TABLE bridge_commands DROP cancelled_api_key_name');
        $this->addSql('ALTER TABLE bridge_commands DROP cancelled_api_key_id');
        $this->addSql('ALTER TABLE bridge_commands DROP api_key_name');
        $this->addSql('ALTER TABLE bridge_commands DROP api_key_id');
        $this->addSql('ALTER TABLE inbox_actions DROP api_key_name');
        $this->addSql('ALTER TABLE inbox_actions DROP api_key_id');
        $this->addSql('ALTER TABLE inbox_items DROP closed_api_key_name');
        $this->addSql('ALTER TABLE inbox_items DROP closed_api_key_id');
    }
}
