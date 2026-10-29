<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261028215800 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the covered work kinds to the experiment definitions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_experiment_definitions ADD covers JSON DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_experiment_definitions DROP covers');
    }
}
