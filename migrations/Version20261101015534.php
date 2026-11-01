<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Yaml\Yaml;

final class Version20261101015534 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Write the shipped Lifecycle copy, whose tech-design-approved rule keeps an open blocker its only false leaf, into every Lifecycle binding, keep the epic branch and the approval rule of each copy, and make the markers of each open card of those projects due';
    }

    /** The epic branch and the approval rule belong to the project, so each copy keeps its own value, or keeps none. */
    public function up(Schema $schema): void
    {
        $source = Yaml::parseFile(__DIR__.'/../config/workflows/lifecycle.yaml');
        if (!\is_array($source) || !\is_int($source['version'] ?? null)) {
            throw new \LogicException('The shipped workflow template "lifecycle" has no version.');
        }
        $this->addSql(
            <<<'SQL'
                UPDATE workflow_bindings
                SET template_version = :version,
                    definition = (CAST(:definition AS JSONB) - 'epicBranch' - 'approvalCoversConflictResolution')
                        || CASE WHEN definition->'epicBranch' IS NULL THEN '{}'::jsonb ELSE jsonb_build_object('epicBranch', definition->'epicBranch') END
                        || CASE WHEN definition->'approvalCoversConflictResolution' IS NULL THEN '{}'::jsonb ELSE jsonb_build_object('approvalCoversConflictResolution', definition->'approvalCoversConflictResolution') END
                WHERE template_key = :key
                SQL,
            ['version' => $source['version'], 'definition' => json_encode($source, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION), 'key' => 'lifecycle'],
        );
        // The minute task then evaluates each card again, so a quiet card gets the blocker hold of the new rule.
        $this->addSql(
            <<<'SQL'
                UPDATE board_cards c
                SET workflow_markers_due_at = LEAST(c.workflow_markers_due_at, :now)
                FROM workflow_bindings b, board_columns col
                WHERE b.project_id = c.project_id AND b.template_key = :key
                    AND col.id = c.column_id AND col.terminal = false
                SQL,
            ['now' => new \DateTimeImmutable(), 'key' => 'lifecycle'],
            ['now' => Types::DATETIME_IMMUTABLE],
        );
    }

    /** The old copies are not kept, so nothing restores them. */
    #[\Override]
    public function down(Schema $schema): void
    {
    }
}
