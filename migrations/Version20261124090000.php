<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Module\Workflow\Template\TemplateVersion;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Yaml\Yaml;

final class Version20261124090000 extends AbstractMigration
{
    private const array TEMPLATES = ['lifecycle', 'simple'];

    #[\Override]
    public function getDescription(): string
    {
        return 'Store the result of a wait on its resume command, mark the card when a wait of a worker changes, and write the shipped template copies, with the marker of a waiting worker, into every binding';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_commands ADD wait_result JSONB DEFAULT NULL');
        $this->addSql('CREATE TRIGGER workflow_mark_bridge_worker_waits AFTER INSERT OR DELETE ON bridge_worker_waits FOR EACH ROW EXECUTE FUNCTION workflow_mark_card()');
        $this->addSql('CREATE TRIGGER workflow_mark_bridge_worker_waits_update AFTER UPDATE ON bridge_worker_waits FOR EACH ROW WHEN ((to_jsonb(OLD) - ARRAY[]::text[]) IS DISTINCT FROM (to_jsonb(NEW) - ARRAY[]::text[])) EXECUTE FUNCTION workflow_mark_card()');

        foreach (self::TEMPLATES as $template) {
            $source = Yaml::parseFile(__DIR__.'/../config/workflows/'.$template.'.yaml');
            if (!\is_array($source)) {
                throw new \LogicException(\sprintf('The shipped workflow template "%s" is not a map.', $template));
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
                    WHERE template_key = :key
                    SQL,
                ['version' => TemplateVersion::of($source), 'definition' => json_encode($source, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION), 'key' => $template],
            );
        }
        // The minute task then evaluates each open card again, so a card that waits today shows its marker with no other event.
        $this->addSql(
            <<<'SQL'
                UPDATE board_cards c
                SET workflow_markers_due_at = LEAST(c.workflow_markers_due_at, :now)
                FROM workflow_bindings b, board_columns col
                WHERE b.project_id = c.project_id AND b.template_key IN ('lifecycle', 'simple')
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
        $this->addSql('DROP TRIGGER workflow_mark_bridge_worker_waits_update ON bridge_worker_waits');
        $this->addSql('DROP TRIGGER workflow_mark_bridge_worker_waits ON bridge_worker_waits');
        $this->addSql('ALTER TABLE bridge_commands DROP wait_result');
    }
}
