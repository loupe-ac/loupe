<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022141100 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Store the origin on inbox items, fill it from the kind, and let a database trigger fill it for an insert that leaves it out';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inbox_items ADD origin VARCHAR(20) DEFAULT NULL');
        $this->addSql("UPDATE inbox_items SET origin = CASE WHEN kind IN ('wait', 'notice', 'workflow') THEN 'loupe' ELSE 'agent' END");

        // The previous image still inserts items with no origin after this migration runs. The trigger fills it from the kind.
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
        // @contract-phase: the trigger fills the origin of an insert by the previous image, so that image never meets a null
        $this->addSql('ALTER TABLE inbox_items ALTER origin SET NOT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER inbox_items_fill_origin ON inbox_items');
        $this->addSql('DROP FUNCTION inbox_items_fill_origin()');
        $this->addSql('ALTER TABLE inbox_items DROP origin');
    }
}
