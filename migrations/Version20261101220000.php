<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101220000 extends AbstractMigration
{
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
        $this->addSql('DROP FUNCTION IF EXISTS inbox_items_close_record(TEXT, inbox_items)');

        $this->addSql("UPDATE inbox_items SET dismissible = false WHERE kind IN ('notice', 'workflow') AND dismissible");
        $this->addSql(<<<'SQL'
            UPDATE inbox_items
            SET state = CASE state
                WHEN 'answered' THEN 'resolved'
                WHEN 'done' THEN 'resolved'
                WHEN 'withdrawn' THEN 'cancelled'
                WHEN 'obsolete' THEN 'cancelled'
                END,
                closed_by = COALESCE(closed_by, 'owner')
            WHERE state IN ('answered', 'done', 'withdrawn', 'obsolete')
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
