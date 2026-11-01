<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101222810 extends AbstractMigration
{
    /** A translation key, as Loupe wrote it into close_note before the close record existed. */
    private const string KEY_PATTERN = '^[a-z0-9_]+(\.[a-z0-9_]+)+$';

    #[\Override]
    public function getDescription(): string
    {
        return 'Drop the inbox triggers that map the old app writes, and the old response columns';
    }

    /**
     * @contract-phase: the release that added the close record kept these objects only for the old app, and no old image runs now.
     */
    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS inbox_reviews_map_old_writes ON inbox_reviews');
        $this->addSql('DROP TRIGGER IF EXISTS inbox_items_map_old_writes ON inbox_items');
        $this->addSql('DROP FUNCTION IF EXISTS inbox_reviews_map_old_writes()');
        $this->addSql('DROP FUNCTION IF EXISTS inbox_items_map_old_writes()');

        // down() does not restore this function, so up() declares it for the last map.
        $this->addSql(str_replace('__KEY__', self::KEY_PATTERN, <<<'SQL'
            CREATE OR REPLACE FUNCTION inbox_items_close_record(old_state TEXT, item inbox_items, OUT closed_by TEXT, OUT close_cause TEXT, OUT note TEXT) AS $$
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

        // A declined row with no closer is an old write too: the new app always names the closer.
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
            WHERE i.state IN ('answered', 'done', 'withdrawn', 'obsolete') OR (i.state = 'declined' AND i.closed_by IS NULL)
            SQL);
        $this->addSql('DROP FUNCTION inbox_items_close_record(TEXT, inbox_items)');

        $this->addSql("UPDATE inbox_items SET dismissible = false WHERE kind IN ('notice', 'workflow') AND dismissible");
        $this->addSql(<<<'SQL'
            UPDATE inbox_items i
            SET note = ir.note
            FROM inbox_reviews ir
            WHERE ir.item_id = i.id AND i.kind = 'review' AND i.state <> 'open' AND i.note IS NULL AND ir.note IS NOT NULL
            SQL);

        $this->addSql('ALTER TABLE inbox_items DROP answer_text');
        $this->addSql('ALTER TABLE inbox_items DROP close_note');
        $this->addSql('ALTER TABLE inbox_items DROP responded_through_agent');
        $this->addSql('ALTER TABLE inbox_reviews DROP note');
    }

    /**
     * Brings the old columns back, filled from the close record. It restores no trigger,
     * because the old app it served is gone.
     */
    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inbox_items ADD answer_text TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE inbox_items ADD close_note TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE inbox_items ADD responded_through_agent BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE inbox_reviews ADD note TEXT DEFAULT NULL');

        $this->addSql("UPDATE inbox_items SET answer_text = note WHERE state = 'resolved' AND kind IN ('question', 'workflow')");
        $this->addSql("UPDATE inbox_items SET close_note = note WHERE NOT (state = 'resolved' AND kind IN ('question', 'workflow')) AND kind <> 'review' AND state <> 'open'");
        $this->addSql("UPDATE inbox_items SET responded_through_agent = COALESCE(closed_by = 'owner-through-agent', false)");
        $this->addSql("UPDATE inbox_reviews ir SET note = i.note FROM inbox_items i WHERE i.id = ir.item_id AND i.state <> 'open'");
    }
}
