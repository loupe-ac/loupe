<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012190803 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Remove the landing.enabled flag, because the host of the instance decides the home page';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("DELETE FROM feature_flag WHERE name = 'landing.enabled'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO feature_flag (name, type, value, tags, options)
            SELECT 'landing.enabled', 'bool', 'false', '[]', NULL
            WHERE NOT EXISTS (
                SELECT 1 FROM feature_flag WHERE name = 'landing.enabled'
            )
            SQL);
    }
}
