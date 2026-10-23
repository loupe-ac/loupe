<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261023173822 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Drop the old inbox wait, pull request and card marker columns, the fill function and the inbox settings table';
    }

    /**
     * @contract-phase: the first release of the marker inbox is deployed and no container of the image before it runs.
     * The code reads and writes none of these columns, and the wait columns that the fill function filled are gone with it.
     */
    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS inbox_card_waits_fill ON inbox_card_waits');
        $this->addSql('DROP FUNCTION IF EXISTS inbox_card_waits_fill()');
        $this->addSql('ALTER TABLE inbox_card_waits DROP "trigger"');
        $this->addSql('ALTER TABLE inbox_card_waits DROP reason');
        $this->addSql('ALTER TABLE forge_pull_requests DROP waits_for_approval_since');
        $this->addSql('DROP INDEX idx_board_cards_markers_missing');
        $this->addSql('DROP INDEX idx_board_cards_markers_due');
        $this->addSql('ALTER TABLE board_cards DROP markers_due_at');
        $this->addSql('ALTER TABLE board_cards DROP markers');
        $this->addSql('ALTER TABLE inbox_project_settings DROP CONSTRAINT FK_5FB0398D166D1F9C');
        $this->addSql('DROP TABLE inbox_project_settings');
    }

    /** The image before this release maps neither `trigger` nor `reason`, so down restores the fill function that writes them. */
    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE inbox_project_settings (id UUID NOT NULL, document_in_review BOOLEAN DEFAULT true NOT NULL, run_blocked BOOLEAN DEFAULT true NOT NULL, run_gave_up BOOLEAN DEFAULT true NOT NULL, run_waiting_for_person BOOLEAN DEFAULT true NOT NULL, pull_request_ready BOOLEAN DEFAULT true NOT NULL, pull_request_fix_stopped BOOLEAN DEFAULT true NOT NULL, card_paused BOOLEAN DEFAULT true NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5FB0398D166D1F9C ON inbox_project_settings (project_id)');
        $this->addSql('ALTER TABLE inbox_project_settings ADD CONSTRAINT FK_5FB0398D166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE board_cards ADD markers JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE board_cards ADD markers_due_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_board_cards_markers_due ON board_cards (markers_due_at) WHERE (markers_due_at IS NOT NULL)');
        $this->addSql('CREATE INDEX idx_board_cards_markers_missing ON board_cards (project_id) WHERE (markers IS NULL)');
        $this->addSql('ALTER TABLE forge_pull_requests ADD waits_for_approval_since TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql("ALTER TABLE inbox_card_waits ADD \"trigger\" VARCHAR(30) DEFAULT 'card-paused' NOT NULL");
        $this->addSql("ALTER TABLE inbox_card_waits ADD reason VARCHAR(200) DEFAULT 'waiting-for-review' NOT NULL");
        $this->addSql('ALTER TABLE inbox_card_waits ALTER "trigger" DROP DEFAULT');
        $this->addSql('ALTER TABLE inbox_card_waits ALTER reason DROP DEFAULT');
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
}
