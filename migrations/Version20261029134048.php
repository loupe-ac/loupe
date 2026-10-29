<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261029134048 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add baseline to experiment definitions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_experiment_definitions ADD baseline VARCHAR(64) DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_experiment_definitions DROP baseline');
    }
}
