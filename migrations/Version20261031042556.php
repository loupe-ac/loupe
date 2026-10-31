<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Yaml\Yaml;

final class Version20261031042556 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Write the shipped Lifecycle and Simple copies, without the work timeout, into every binding, and end the work-timeout pauses';
    }

    /** The epic branch and the approval rule belong to the project, so each copy keeps its own value, or keeps none. */
    public function up(Schema $schema): void
    {
        foreach (['lifecycle', 'simple'] as $key) {
            $source = Yaml::parseFile(__DIR__.'/../config/workflows/'.$key.'.yaml');
            if (!\is_array($source) || !\is_int($source['version'] ?? null)) {
                throw new \LogicException(\sprintf('The shipped workflow template "%s" has no version.', $key));
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
                ['version' => $source['version'], 'definition' => json_encode($source, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION), 'key' => $key],
            );
        }

        // The pause kind no longer exists. An active pause ends, so its rule runs again, and the kept row reads as a stopped worker.
        // The history records each release with the kind the pause had.
        $this->addSql(<<<'SQL'
            WITH active AS (
                SELECT id, card_id, project_id, reason, rule_id
                FROM card_pauses
                WHERE kind = 'work-timeout' AND released_at IS NULL
            ), released AS (
                UPDATE card_pauses
                SET released_at = COALESCE(released_at, now() AT TIME ZONE 'UTC'),
                    release_reason = COALESCE(release_reason, 'work-timeout-removed'),
                    kind = 'work-stopped'
                WHERE kind = 'work-timeout'
            )
            INSERT INTO board_card_events (id, card_id, project_id, kind, actor_kind, detail, occurred_at)
            SELECT gen_random_uuid(), a.card_id, a.project_id, 'pause-released', 'system',
                   jsonb_build_object('kind', 'work-timeout', 'reason', a.reason, 'ruleId', a.rule_id, 'releaseReason', 'work-timeout-removed'),
                   now() AT TIME ZONE 'UTC'
            FROM active a
            SQL);
    }

    /** The old copies are not kept, so nothing restores them. */
    #[\Override]
    public function down(Schema $schema): void
    {
    }
}
