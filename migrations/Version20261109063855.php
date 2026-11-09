<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261109063855 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the optional formula columns to the saved graphs of the dashboard';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE insights_saved_graphs ADD formula_rows TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE insights_saved_graphs ADD formula VARCHAR(200) DEFAULT NULL');
        $this->addSql('ALTER TABLE insights_saved_graphs ADD format VARCHAR(16) DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE insights_saved_graphs DROP formula_rows');
        $this->addSql('ALTER TABLE insights_saved_graphs DROP formula');
        $this->addSql('ALTER TABLE insights_saved_graphs DROP format');
    }
}
