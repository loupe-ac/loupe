<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261111003120 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Seed the observability.evaluate_card_trace_rate_permille flag so upgraded instances can change the card evaluation trace rate';
    }

    /**
     * The installer seeds this flag, but it only ever runs once, so an instance
     * that upgrades has no row, and the admin cannot offer a field for a flag
     * it does not know about.
     */
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO feature_flag (name, type, value, tags, options)
            SELECT 'observability.evaluate_card_trace_rate_permille', 'int', '10', '[]', NULL
            WHERE NOT EXISTS (
                SELECT 1 FROM feature_flag WHERE name = 'observability.evaluate_card_trace_rate_permille'
            )
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM feature_flag WHERE name = 'observability.evaluate_card_trace_rate_permille'");
    }
}
