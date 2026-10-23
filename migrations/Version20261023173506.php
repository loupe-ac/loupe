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
        return 'Drop the insert trigger that fills the origin of inbox items';
    }

    public function up(Schema $schema): void
    {
        // @contract-phase: the origin lives on inbox_items and no image inserts an item without it, so the trigger has no caller.
        $this->addSql('DROP TRIGGER inbox_items_fill_origin ON inbox_items');
        // @contract-phase: the trigger is gone, so nothing calls the function.
        $this->addSql('DROP FUNCTION inbox_items_fill_origin()');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
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
