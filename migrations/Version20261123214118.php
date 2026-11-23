<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261123214118 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the recommended option, confidence, option costs and details to inbox items';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inbox_items ADD recommended_option INT DEFAULT NULL');
        $this->addSql('ALTER TABLE inbox_items ADD confidence VARCHAR(10) DEFAULT NULL');
        $this->addSql('ALTER TABLE inbox_items ADD option_costs JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE inbox_items ADD details JSON DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inbox_items DROP recommended_option');
        $this->addSql('ALTER TABLE inbox_items DROP confidence');
        $this->addSql('ALTER TABLE inbox_items DROP option_costs');
        $this->addSql('ALTER TABLE inbox_items DROP details');
    }
}
