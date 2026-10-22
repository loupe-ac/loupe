<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022013012 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Store the marker key, the label, its values and the spell start on the inbox card waits, fill the marker key from the trigger, and let a database trigger fill the old trigger and reason columns';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE inbox_card_waits ADD marker_key VARCHAR(100) DEFAULT '' NOT NULL");
        $this->addSql("ALTER TABLE inbox_card_waits ADD label VARCHAR(200) DEFAULT '' NOT NULL");
        $this->addSql("ALTER TABLE inbox_card_waits ADD params JSONB DEFAULT '{}' NOT NULL");
        $this->addSql('ALTER TABLE inbox_card_waits ADD spell_started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE inbox_card_waits SET marker_key = CASE "trigger"
                WHEN 'pull-request-ready' THEN 'waits-for-approval'
                WHEN 'pull-request-fix-stopped' THEN 'waits-for-approval'
                WHEN 'document-in-review' THEN 'document-in-review'
                WHEN 'run-blocked' THEN 'run-needs-you'
                WHEN 'run-gave-up' THEN 'run-needs-you'
                WHEN 'run-waiting-for-person' THEN 'run-needs-you'
                WHEN 'card-paused' THEN 'paused'
                ELSE '' END
            SQL);

        // A card wait gets no value here on purpose, so its insert fails on the NOT NULL columns. The first release writes none.
        $this->addSql(<<<'SQL'
            CREATE FUNCTION inbox_card_waits_fill() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                run_state text;
            BEGIN
                IF NEW.type = 'worker-run' THEN
                    SELECT state INTO run_state FROM bridge_worker_runs WHERE id = NEW.run_id;
                END IF;
                IF NEW."trigger" IS NULL OR NEW."trigger" = '' THEN
                    NEW."trigger" = CASE NEW.type
                        WHEN 'pull-request' THEN 'pull-request-ready'
                        WHEN 'document' THEN 'document-in-review'
                        WHEN 'card-pause' THEN 'card-paused'
                        WHEN 'worker-run' THEN CASE run_state
                            WHEN 'blocked' THEN 'run-blocked'
                            WHEN 'gave-up' THEN 'run-gave-up'
                            ELSE 'run-waiting-for-person' END
                        ELSE NULL END;
                END IF;
                IF NEW.reason IS NULL OR NEW.reason = '' THEN
                    NEW.reason = CASE NEW.type
                        WHEN 'pull-request' THEN 'waiting-for-review'
                        WHEN 'document' THEN 'waiting-for-review'
                        WHEN 'card-pause' THEN COALESCE((SELECT kind FROM card_pauses WHERE id = NEW.pause_id), 'work-stopped')
                        WHEN 'worker-run' THEN CASE run_state
                            WHEN 'blocked' THEN 'blocked'
                            WHEN 'gave-up' THEN 'gave-up'
                            ELSE 'waits-for-person' END
                        ELSE NULL END;
                END IF;
                IF NEW.marker_key = '' THEN
                    NEW.marker_key = CASE NEW."trigger"
                        WHEN 'pull-request-ready' THEN 'waits-for-approval'
                        WHEN 'pull-request-fix-stopped' THEN 'waits-for-approval'
                        WHEN 'document-in-review' THEN 'document-in-review'
                        WHEN 'run-blocked' THEN 'run-needs-you'
                        WHEN 'run-gave-up' THEN 'run-needs-you'
                        WHEN 'run-waiting-for-person' THEN 'run-needs-you'
                        WHEN 'card-paused' THEN 'paused'
                        ELSE '' END;
                END IF;
                RETURN NEW;
            END;
            $$
            SQL);
        $this->addSql('CREATE TRIGGER inbox_card_waits_fill BEFORE INSERT ON inbox_card_waits FOR EACH ROW EXECUTE FUNCTION inbox_card_waits_fill()');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER inbox_card_waits_fill ON inbox_card_waits');
        $this->addSql('DROP FUNCTION inbox_card_waits_fill()');
        $this->addSql('ALTER TABLE inbox_card_waits DROP spell_started_at');
        $this->addSql('ALTER TABLE inbox_card_waits DROP params');
        $this->addSql('ALTER TABLE inbox_card_waits DROP label');
        $this->addSql('ALTER TABLE inbox_card_waits DROP marker_key');
    }
}
