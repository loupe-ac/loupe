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
     * with the triggers.
     */
    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inbox_items ADD closed_by VARCHAR(30) DEFAULT NULL');
        $this->addSql('ALTER TABLE inbox_items ADD close_cause VARCHAR(30) DEFAULT NULL');
        $this->addSql('ALTER TABLE inbox_items ADD note TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE inbox_items ADD dismissible BOOLEAN DEFAULT true NOT NULL');

        $this->addSql(str_replace('__KEY__', self::KEY_PATTERN, <<<'SQL'
            CREATE FUNCTION inbox_items_close_record(old_state TEXT, item inbox_items, OUT closed_by TEXT, OUT close_cause TEXT, OUT note TEXT) AS $$
            DECLARE
                review_note TEXT;
                review_by_rule BOOLEAN;
                review_has_verdict BOOLEAN;
                command_done BOOLEAN;
            BEGIN
                SELECT ir.note, rv.workflow_rule IS NOT NULL, ir.verdict IS NOT NULL
                INTO review_note, review_by_rule, review_has_verdict
                FROM inbox_reviews ir
                LEFT JOIN reviews rv ON rv.id = ir.document_review_id
                WHERE ir.item_id = item.id;
                review_by_rule := COALESCE(review_by_rule, false);
                review_has_verdict := COALESCE(review_has_verdict, false);

                command_done := EXISTS (
                    SELECT 1
                    FROM inbox_actions a
                    JOIN bridge_worker_runs r ON r.work_request_id = a.work_request_id
                    WHERE a.item_id = item.id AND a.kind = 'run-command' AND a.cancelled_at IS NULL AND r.state = 'succeeded'
                );

                note := CASE
                    WHEN item.kind = 'review' AND review_note IS NOT NULL THEN review_note
                    WHEN old_state = 'done' AND item.origin = 'loupe' AND item.kind = 'notice' AND item.close_note ~ '__KEY__' THEN NULL
                    ELSE COALESCE(item.answer_text, item.close_note)
                END;
                close_cause := CASE
                    WHEN old_state = 'withdrawn' AND item.kind = 'workflow' THEN 'rule-withdrew'
                    WHEN old_state = 'withdrawn' THEN 'withdrawn'
                    WHEN old_state = 'obsolete' THEN 'card-finished'
                    WHEN old_state = 'done' AND item.kind = 'todo' AND command_done THEN 'command-succeeded'
                    WHEN old_state = 'done' AND item.origin = 'loupe' AND item.kind = 'notice' THEN 'fact-ended'
                    WHEN old_state = 'done' AND item.origin = 'loupe' AND (item.kind = 'wait' OR (item.kind = 'review' AND NOT review_has_verdict)) THEN 'cause-ended'
                    ELSE NULL
                END;
                closed_by := CASE
                    WHEN old_state = 'withdrawn' AND item.kind = 'workflow' THEN 'rule'
                    WHEN old_state = 'withdrawn' THEN 'agent'
                    WHEN old_state = 'obsolete' THEN 'loupe'
                    WHEN old_state = 'done' AND item.kind = 'todo' AND command_done THEN 'loupe'
                    WHEN old_state = 'done' AND item.origin = 'loupe' AND (item.kind IN ('wait', 'notice') OR (item.kind = 'review' AND NOT review_has_verdict)) THEN 'loupe'
                    WHEN old_state = 'done' AND item.kind = 'review' AND review_by_rule THEN 'rule'
                    WHEN item.responded_through_agent THEN 'owner-through-agent'
                    ELSE 'owner'
                END;
            END;
            $$ LANGUAGE plpgsql
            SQL));

        $this->addSql(<<<'SQL'
            UPDATE inbox_items i
            SET (closed_by, close_cause, note) = (SELECT r.closed_by, r.close_cause, r.note FROM inbox_items_close_record(i.state, i) r),
                state = CASE i.state
                    WHEN 'answered' THEN 'resolved'
                    WHEN 'done' THEN 'resolved'
                    WHEN 'withdrawn' THEN 'cancelled'
                    WHEN 'obsolete' THEN 'cancelled'
                    ELSE i.state
                END
            WHERE i.state <> 'open'
            SQL);
        $this->addSql("UPDATE inbox_items SET dismissible = false WHERE kind IN ('notice', 'workflow')");

        $this->addSql(<<<'SQL'
            CREATE FUNCTION inbox_items_map_old_writes() RETURNS trigger AS $$
            DECLARE
                mapped RECORD;
            BEGIN
                IF NEW.kind IN ('notice', 'workflow') THEN
                    NEW.dismissible := false;
                END IF;

                -- Only the old app writes the old states and the legacy columns. The new app writes
                -- resolved, cancelled, and declined with a closer.
                IF NEW.state NOT IN ('answered', 'done', 'withdrawn', 'obsolete')
                    AND NOT (NEW.state = 'declined' AND NEW.closed_by IS NULL)
                    AND NOT (TG_OP = 'UPDATE' AND NEW.state = 'declined'
                        AND (NEW.answer_text, NEW.close_note, NEW.responded_through_agent) IS DISTINCT FROM (OLD.answer_text, OLD.close_note, OLD.responded_through_agent)) THEN
                    RETURN NEW;
                END IF;

                -- The old app stores the review row after the item, in the same transaction.
                IF NEW.kind = 'review' AND NEW.state = 'done' THEN
                    PERFORM set_config('loupe.inbox_old_review_items', COALESCE(current_setting('loupe.inbox_old_review_items', true), '') || ',' || NEW.id::text, true);
                END IF;

                SELECT * INTO mapped FROM inbox_items_close_record(NEW.state, NEW);
                NEW.closed_by := mapped.closed_by;
                NEW.close_cause := mapped.close_cause;
                NEW.note := mapped.note;
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
            SQL);
        $this->addSql('CREATE TRIGGER inbox_items_map_old_writes BEFORE INSERT OR UPDATE ON inbox_items FOR EACH ROW EXECUTE FUNCTION inbox_items_map_old_writes()');

        $this->addSql(<<<'SQL'
            CREATE FUNCTION inbox_reviews_map_old_writes() RETURNS trigger AS $$
            BEGIN
                IF position(',' || NEW.item_id::text IN COALESCE(current_setting('loupe.inbox_old_review_items', true), '')) = 0 THEN
                    RETURN NULL;
                END IF;

                UPDATE inbox_items i
                SET (closed_by, close_cause, note) = (SELECT r.closed_by, r.close_cause, r.note FROM inbox_items_close_record('done', i) r)
                WHERE i.id = NEW.item_id;
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql
            SQL);
        $this->addSql('CREATE TRIGGER inbox_reviews_map_old_writes AFTER INSERT OR UPDATE ON inbox_reviews FOR EACH ROW EXECUTE FUNCTION inbox_reviews_map_old_writes()');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER inbox_reviews_map_old_writes ON inbox_reviews');
        $this->addSql('DROP FUNCTION inbox_reviews_map_old_writes()');
        $this->addSql('DROP TRIGGER inbox_items_map_old_writes ON inbox_items');
        $this->addSql('DROP FUNCTION inbox_items_map_old_writes()');
        $this->addSql('DROP FUNCTION inbox_items_close_record(TEXT, inbox_items)');

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
