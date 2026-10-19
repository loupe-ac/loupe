<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018234232 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the answering run to the workflow rule states';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workflow_rule_states ADD answering_run_id UUID DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workflow_rule_states DROP answering_run_id');
    }
}
