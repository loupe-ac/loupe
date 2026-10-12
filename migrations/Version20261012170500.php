<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012170500 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Store the conclusion each agent review check shows and the override a card history announced';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE agent_reviews ADD check_conclusion VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE agent_reviews ADD override_announced_for VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE agent_review_posts ADD check_conclusion VARCHAR(20) DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE agent_review_posts DROP check_conclusion');
        $this->addSql('ALTER TABLE agent_reviews DROP override_announced_for');
        $this->addSql('ALTER TABLE agent_reviews DROP check_conclusion');
    }
}
