<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261027174754 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the start time of the project work hold to the rule states';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workflow_rule_states ADD held_by_work_hold_since TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workflow_rule_states DROP held_by_work_hold_since');
    }
}
