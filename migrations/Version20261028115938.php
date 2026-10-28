<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261028115938 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the headline of the experiment page to the experiment definitions';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_experiment_definitions ADD headline JSON DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_experiment_definitions DROP headline');
    }
}
