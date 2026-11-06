<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Yaml\Yaml;

final class Version20261106133000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Write the shipped Lifecycle copy, with the start approval of the interactive tech design session, into every Lifecycle binding, and mark the presence rule as already true on each card whose session switch is on so no terminal opens at deploy';
    }

    /**
     * The epic branch and the approval rule belong to the project, so each copy keeps its own value, or keeps none.
     * The engine fires a rule only when it turns true. A card whose switch is On gets a true state for the presence rule,
     * so a switch write from before the deploy fires nothing. The next pass sets the state false on a card where the rule
     * does not hold, and resets it on a card outside the slot, so a later write by a person fires as normal.
     */
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
                WHERE template_key = 'lifecycle'
                SQL,
            ['version' => $source['version'], 'definition' => json_encode($source, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION)],
        );
        $this->addSql(
            <<<'SQL'
                INSERT INTO workflow_rule_states (id, card_id, project_id, rule_id, truth, updated_at)
                SELECT gen_random_uuid(), c.id, c.project_id, 'tech-design-session-presence', TRUE, NOW()
                FROM board_cards c
                JOIN workflow_bindings b ON b.project_id = c.project_id AND b.template_key = 'lifecycle'
                JOIN board_card_values v ON v.card_id = c.id AND v.key = 'tech-design-interactive' AND v.value = 'true'::jsonb
                ON CONFLICT (card_id, rule_id) DO NOTHING
                SQL,
        );
    }

    /** The old copies are not kept, so nothing restores them. */
    #[\Override]
    public function down(Schema $schema): void
    {
    }
}
