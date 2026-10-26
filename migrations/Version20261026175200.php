<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026175200 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Turn the human review switch on for each open card that an artifact reaches, and record the artifacts it has seen';
    }

    /**
     * An artifact reaches a card when it is linked to the card or to its parent. A card in a terminal column and a card
     * whose switch is on already keep their state. Each changed card gets one history row with the cause `rollout`.
     */
    public function up(Schema $schema): void
    {
        $this->addSql(
            <<<'SQL'
                WITH reach AS (
                    SELECT c.id AS card_id, c.project_id, jsonb_agg(DISTINCT a.artifact_id::text ORDER BY a.artifact_id::text) AS artifact_ids
                    FROM board_cards c
                    JOIN board_columns k ON k.id = c.column_id AND NOT k.terminal
                    JOIN board_card_artifacts a ON a.card_id = c.id OR a.card_id = c.parent_card_id
                    WHERE NOT c.requires_human_review
                    GROUP BY c.id, c.project_id
                ), updated AS (
                    UPDATE board_cards c
                    SET requires_human_review = true, human_review_seen_artifacts = r.artifact_ids
                    FROM reach r
                    WHERE c.id = r.card_id
                    RETURNING c.id, c.project_id, r.artifact_ids
                )
                INSERT INTO board_card_events (id, card_id, project_id, kind, actor_kind, detail, occurred_at)
                SELECT gen_random_uuid(), u.id, u.project_id, 'human-review-changed', 'system',
                       jsonb_build_object('value', true, 'cause', 'rollout', 'ruleId', NULL, 'artifactIds', u.artifact_ids),
                       now() AT TIME ZONE 'UTC'
                FROM updated u
                SQL,
        );
    }

    /** The switch may have changed since, so nothing restores the old values. */
    #[\Override]
    public function down(Schema $schema): void
    {
    }
}
