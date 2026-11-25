<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124130000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Mark the stranded wait whose parked run a card change stops';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_worker_waits ADD stop_requested_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN bridge_worker_waits.stop_requested_at IS '(DC2Type:datetime_immutable)'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_worker_waits DROP stop_requested_at');
    }
}
