<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106005306 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Store whether the review state of a pull request is the review decision of the forge';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forge_pull_requests ADD review_from_rules BOOLEAN DEFAULT false NOT NULL');
        $this->addSql("UPDATE forge_pull_requests SET review_from_rules = true WHERE review IN ('required', 'changes-requested')");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forge_pull_requests DROP review_from_rules');
    }
}
