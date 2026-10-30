<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030141507 extends AbstractMigration
{
    /**
     * A translation key, as Loupe wrote it into close_note before the close record existed.
     * The pattern is for POSIX regular expressions in PostgreSQL.
     */
    private const string KEY_PATTERN = '^[a-z0-9_]+(\.[a-z0-9_]+)+$';

    #[\Override]
    public function getDescription(): string
    {
        return 'Store who closed an inbox item and why, map the old states, and keep a trigger for the old app';
    }

    /**
     * The old columns answer_text, close_note, responded_through_agent and
     * inbox_reviews.note stay, nullable or defaulted, because the old app still
     * runs against this schema during the deploy. A later migration drops them
     * with the trigger.
     */
    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inbox_items ADD closed_by VARCHAR(30) DEFAULT NULL');
        $this->addSql('ALTER TABLE inbox_items ADD close_cause VARCHAR(30) DEFAULT NULL');
        $this->addSql('ALTER TABLE inbox_items ADD note TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE inbox_items ADD dismissible BOOLEAN DEFAULT true NOT NULL');

        $key = self::KEY_PATTERN;
        $this->addSql(<<<SQL
            WITH command_done AS (
                SELECT DISTINCT a.item_id
                FROM inbox_actions a
                JOIN bridge_worker_runs r ON r.work_request_id = a.work_request_id
                WHERE a.kind = 'run-command' AND a.cancelled_at IS NULL AND r.state = 'succeeded'
            ),
            reviewed AS (
                SELECT ir.item_id, ir.verdict IS NOT NULL AS has_verdict, ir.note, rv.workflow_rule IS NOT NULL AS by_rule
                FROM inbox_reviews ir
                LEFT JOIN reviews rv ON rv.id = ir.document_review_id
            ),
            mapped AS (
                SELECT i.id,
                    CASE
                        WHEN i.state = 'open' THEN NULL
                        WHEN i.state = 'withdrawn' AND i.kind = 'workflow' THEN 'rule'
                        WHEN i.state = 'withdrawn' THEN 'agent'
                        WHEN i.state = 'obsolete' THEN 'loupe'
                        WHEN i.state = 'done' AND i.kind = 'todo' AND cd.item_id IS NOT NULL THEN 'loupe'
                        WHEN i.state = 'done' AND i.origin = 'loupe' AND (i.kind IN ('wait', 'notice') OR (i.kind = 'review' AND NOT COALESCE(rw.has_verdict, false))) THEN 'loupe'
                        WHEN i.state = 'done' AND i.kind = 'review' AND COALESCE(rw.by_rule, false) THEN 'rule'
                        WHEN i.responded_through_agent THEN 'owner-through-agent'
                        ELSE 'owner'
                    END AS closed_by,
                    CASE
                        WHEN i.state = 'withdrawn' AND i.kind = 'workflow' THEN 'rule-withdrew'
                        WHEN i.state = 'withdrawn' THEN 'withdrawn'
                        WHEN i.state = 'obsolete' THEN 'card-finished'
                        WHEN i.state = 'done' AND i.kind = 'todo' AND cd.item_id IS NOT NULL THEN 'command-succeeded'
                        WHEN i.state = 'done' AND i.origin = 'loupe' AND i.kind = 'notice' THEN 'fact-ended'
                        WHEN i.state = 'done' AND i.origin = 'loupe' AND (i.kind = 'wait' OR (i.kind = 'review' AND NOT COALESCE(rw.has_verdict, false))) THEN 'cause-ended'
                        ELSE NULL
                    END AS close_cause,
                    CASE
                        WHEN i.kind = 'review' AND rw.note IS NOT NULL THEN rw.note
                        WHEN i.state = 'done' AND i.origin = 'loupe' AND i.kind = 'notice' AND i.close_note ~ '{$key}' THEN NULL
                        ELSE COALESCE(i.answer_text, i.close_note)
                    END AS note
                FROM inbox_items i
                LEFT JOIN command_done cd ON cd.item_id = i.id
                LEFT JOIN reviewed rw ON rw.item_id = i.id
            )
            UPDATE inbox_items i
            SET closed_by = m.closed_by,
                close_cause = m.close_cause,
                note = m.note,
                state = CASE i.state
                    WHEN 'answered' THEN 'resolved'
                    WHEN 'done' THEN 'resolved'
                    WHEN 'withdrawn' THEN 'cancelled'
                    WHEN 'obsolete' THEN 'cancelled'
                    ELSE i.state
                END
            FROM mapped m
            WHERE m.id = i.id AND i.state <> 'open'
            SQL);
        $this->addSql("UPDATE inbox_items SET dismissible = false WHERE kind IN ('notice', 'workflow')");

        $this->addSql(str_replace('__KEY__', $key, <<<'SQL'
            CREATE FUNCTION inbox_items_map_old_writes() RETURNS trigger AS $$
            DECLARE
                review_note TEXT;
                review_by_rule BOOLEAN;
                review_has_verdict BOOLEAN;
                command_done BOOLEAN;
            BEGIN
                IF NEW.kind IN ('notice', 'workflow') THEN
                    NEW.dismissible := false;
                END IF;

                -- The new app writes resolved, cancelled, and declined with a closer. Any other closed value
                -- comes from the old app, which knows none of the new columns.
                IF NEW.state NOT IN ('answered', 'done', 'withdrawn', 'obsolete')
                    AND NOT (NEW.state = 'declined' AND NEW.closed_by IS NULL) THEN
                    RETURN NEW;
                END IF;

                SELECT ir.note, rv.workflow_rule IS NOT NULL, ir.verdict IS NOT NULL
                INTO review_note, review_by_rule, review_has_verdict
                FROM inbox_reviews ir
                LEFT JOIN reviews rv ON rv.id = ir.document_review_id
                WHERE ir.item_id = NEW.id;
                review_by_rule := COALESCE(review_by_rule, false);
                review_has_verdict := COALESCE(review_has_verdict, false);

                command_done := EXISTS (
                    SELECT 1
                    FROM inbox_actions a
                    JOIN bridge_worker_runs r ON r.work_request_id = a.work_request_id
                    WHERE a.item_id = NEW.id AND a.kind = 'run-command' AND a.cancelled_at IS NULL AND r.state = 'succeeded'
                );

                NEW.note := CASE
                    WHEN NEW.kind = 'review' AND review_note IS NOT NULL THEN review_note
                    WHEN NEW.state = 'done' AND NEW.origin = 'loupe' AND NEW.kind = 'notice' AND NEW.close_note ~ '__KEY__' THEN NULL
                    ELSE COALESCE(NEW.answer_text, NEW.close_note)
                END;
                NEW.close_cause := CASE
                    WHEN NEW.state = 'withdrawn' AND NEW.kind = 'workflow' THEN 'rule-withdrew'
                    WHEN NEW.state = 'withdrawn' THEN 'withdrawn'
                    WHEN NEW.state = 'obsolete' THEN 'card-finished'
                    WHEN NEW.state = 'done' AND NEW.kind = 'todo' AND command_done THEN 'command-succeeded'
                    WHEN NEW.state = 'done' AND NEW.origin = 'loupe' AND NEW.kind = 'notice' THEN 'fact-ended'
                    WHEN NEW.state = 'done' AND NEW.origin = 'loupe' AND (NEW.kind = 'wait' OR (NEW.kind = 'review' AND NOT review_has_verdict)) THEN 'cause-ended'
                    ELSE NULL
                END;
                NEW.closed_by := CASE
                    WHEN NEW.state = 'withdrawn' AND NEW.kind = 'workflow' THEN 'rule'
                    WHEN NEW.state = 'withdrawn' THEN 'agent'
                    WHEN NEW.state = 'obsolete' THEN 'loupe'
                    WHEN NEW.state = 'done' AND NEW.kind = 'todo' AND command_done THEN 'loupe'
                    WHEN NEW.state = 'done' AND NEW.origin = 'loupe' AND (NEW.kind IN ('wait', 'notice') OR (NEW.kind = 'review' AND NOT review_has_verdict)) THEN 'loupe'
                    WHEN NEW.state = 'done' AND NEW.kind = 'review' AND review_by_rule THEN 'rule'
                    WHEN NEW.responded_through_agent THEN 'owner-through-agent'
                    ELSE 'owner'
                END;
                NEW.state := CASE NEW.state
                    WHEN 'answered' THEN 'resolved'
                    WHEN 'done' THEN 'resolved'
                    WHEN 'withdrawn' THEN 'cancelled'
                    WHEN 'obsolete' THEN 'cancelled'
                    ELSE NEW.state
                END;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL));
        $this->addSql('CREATE TRIGGER inbox_items_map_old_writes BEFORE INSERT OR UPDATE ON inbox_items FOR EACH ROW EXECUTE FUNCTION inbox_items_map_old_writes()');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER inbox_items_map_old_writes ON inbox_items');
        $this->addSql('DROP FUNCTION inbox_items_map_old_writes()');

        $this->addSql("UPDATE inbox_items SET answer_text = note WHERE state = 'resolved' AND kind IN ('question', 'workflow')");
        $this->addSql("UPDATE inbox_items SET close_note = note WHERE NOT (state = 'resolved' AND kind IN ('question', 'workflow')) AND kind <> 'review' AND state <> 'open'");
        $this->addSql("UPDATE inbox_items SET responded_through_agent = COALESCE(closed_by = 'owner-through-agent', false)");
        $this->addSql("UPDATE inbox_reviews ir SET note = i.note FROM inbox_items i WHERE i.id = ir.item_id AND i.state <> 'open'");
        $this->addSql(<<<'SQL'
            UPDATE inbox_items
            SET state = CASE
                WHEN state = 'resolved' AND kind IN ('question', 'workflow') THEN 'answered'
                WHEN state = 'resolved' THEN 'done'
                WHEN state = 'cancelled' AND close_cause IN ('withdrawn', 'rule-withdrew') THEN 'withdrawn'
                WHEN state = 'cancelled' THEN 'obsolete'
                ELSE state
            END
            SQL);

        $this->addSql('ALTER TABLE inbox_items DROP closed_by');
        $this->addSql('ALTER TABLE inbox_items DROP close_cause');
        $this->addSql('ALTER TABLE inbox_items DROP note');
        $this->addSql('ALTER TABLE inbox_items DROP dismissible');
    }
}
