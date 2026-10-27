<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261027042011 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Record the name of each posted agent review and live review check';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE agent_review_posts ADD check_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE agent_reviews ADD check_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE board_site_review_check_states ADD check_name VARCHAR(255) DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE agent_review_posts DROP check_name');
        $this->addSql('ALTER TABLE agent_reviews DROP check_name');
        $this->addSql('ALTER TABLE board_site_review_check_states DROP check_name');
    }
}
