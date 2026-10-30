<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030150159 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the table of cards that have an evaluation waiting in the queue';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE workflow_queued_evaluations (card_id UUID NOT NULL, queued_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (card_id))');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE workflow_queued_evaluations');
    }
}
