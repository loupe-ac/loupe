<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261023173506 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Drop the origin column of inbox asks and the insert trigger that fills the origin of inbox items';
    }

    public function up(Schema $schema): void
    {
        // @contract-phase: the origin lives on inbox_items and no image inserts an item without it, so the trigger and inbox_asks.origin have no reader or writer.
        $this->addSql('DROP TRIGGER inbox_items_fill_origin ON inbox_items');
        // @contract-phase: the trigger is gone, so nothing calls the function.
        $this->addSql('DROP FUNCTION inbox_items_fill_origin()');
        // @contract-phase: the ask origin moved to inbox_items.origin, and no code reads or writes inbox_asks.origin.
        $this->addSql('ALTER TABLE inbox_asks DROP origin');
    }

    /** The asks of Loupe come back as agent asks, because the dropped column is not recoverable. */
    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inbox_asks ADD origin VARCHAR(20) DEFAULT \'agent\' NOT NULL');
        $this->addSql(<<<'SQL'
            CREATE FUNCTION inbox_items_fill_origin() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.origin IS NULL THEN
                    NEW.origin = CASE WHEN NEW.kind IN ('wait', 'notice', 'workflow') THEN 'loupe' ELSE 'agent' END;
                END IF;
                RETURN NEW;
            END;
            $$
            SQL);
        $this->addSql('CREATE TRIGGER inbox_items_fill_origin BEFORE INSERT ON inbox_items FOR EACH ROW EXECUTE FUNCTION inbox_items_fill_origin()');
    }
}
