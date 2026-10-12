<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012172025 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Store the approver each agent review check names as its override';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE agent_review_posts ADD check_approver VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE agent_reviews ADD check_approver VARCHAR(255) DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE agent_reviews DROP check_approver');
        $this->addSql('ALTER TABLE agent_review_posts DROP check_approver');
    }
}
