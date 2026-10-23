<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022163014 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the workflow rule that wrote a verdict to reviews.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reviews ADD workflow_rule VARCHAR(100) DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reviews DROP workflow_rule');
    }
}
