<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Module\Workflow\Template\TemplateVersion;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Yaml\Yaml;

final class Version20261124091500 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Write the shipped Lifecycle template copy, with the marker of a wait that its offline bridge cannot resume, into every Lifecycle binding';
    }

    public function up(Schema $schema): void
    {
        $source = Yaml::parseFile(__DIR__.'/../config/workflows/lifecycle.yaml');
        if (!\is_array($source)) {
            throw new \LogicException('The shipped workflow template "lifecycle" is not a map.');
        }
        // The epic branch, the setting values and the approval rule belong to the project, so each copy keeps its own value, or keeps none.
        $this->addSql(
            <<<'SQL'
                UPDATE workflow_bindings
                SET template_version = :version,
                    definition = (CAST(:definition AS JSONB) - 'epicBranch' - 'settingValues' - 'approvalCoversConflictResolution')
                        || CASE WHEN definition->'epicBranch' IS NULL THEN '{}'::jsonb ELSE jsonb_build_object('epicBranch', definition->'epicBranch') END
                        || CASE WHEN definition->'settingValues' IS NULL THEN '{}'::jsonb ELSE jsonb_build_object('settingValues', definition->'settingValues') END
                        || CASE WHEN definition->'approvalCoversConflictResolution' IS NULL THEN '{}'::jsonb ELSE jsonb_build_object('approvalCoversConflictResolution', definition->'approvalCoversConflictResolution') END
                WHERE template_key = 'lifecycle'
                SQL,
            ['version' => TemplateVersion::of($source), 'definition' => json_encode($source, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION)],
        );
        // The minute task then evaluates each open card again, so a stranded wait shows its marker with no other event.
        $this->addSql(
            <<<'SQL'
                UPDATE board_cards c
                SET workflow_markers_due_at = LEAST(c.workflow_markers_due_at, :now)
                FROM workflow_bindings b, board_columns col
                WHERE b.project_id = c.project_id AND b.template_key = 'lifecycle'
                    AND col.id = c.column_id AND col.terminal = false
                SQL,
            ['now' => new \DateTimeImmutable()],
            ['now' => Types::DATETIME_IMMUTABLE],
        );
    }

    /** The old copies are not kept, so nothing restores them. */
    #[\Override]
    public function down(Schema $schema): void
    {
    }
}
