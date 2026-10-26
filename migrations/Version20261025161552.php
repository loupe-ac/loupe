<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261025161552 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Mark decision answers and reviews that an agent recorded for the owner';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE decision_answers ADD answered_through_agent BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE reviews ADD through_agent BOOLEAN DEFAULT false NOT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE decision_answers DROP answered_through_agent');
        $this->addSql('ALTER TABLE reviews DROP through_agent');
    }
}
