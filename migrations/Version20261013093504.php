<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013093504 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Seed the open_graph.image flag so upgraded instances can pick the preview image';
    }

    /**
     * The installer seeds this flag, but it only ever runs once, so an instance
     * that upgrades has no row, and the admin cannot offer a choice for a flag
     * it does not know about.
     */
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO feature_flag (name, type, value, tags, options)
            SELECT 'open_graph.image', 'select', '"hero"', '[]', '["hero","board"]'
            WHERE NOT EXISTS (
                SELECT 1 FROM feature_flag WHERE name = 'open_graph.image'
            )
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM feature_flag WHERE name = 'open_graph.image'");
    }
}
