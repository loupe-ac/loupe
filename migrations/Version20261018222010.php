<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018222010 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the condition parts that held at the open of a request to the workflow rule states';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workflow_rule_states ADD causes_at_open JSON DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workflow_rule_states DROP causes_at_open');
    }
}
