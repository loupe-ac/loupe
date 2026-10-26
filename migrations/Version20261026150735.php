<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026150735 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Drop the origin column of inbox asks';
    }

    public function up(Schema $schema): void
    {
        // @contract-phase: #1221 stopped every read and write of the column, and production runs it.
        $this->addSql('ALTER TABLE inbox_asks DROP origin');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE inbox_asks ADD origin VARCHAR(20) DEFAULT 'agent' NOT NULL");
    }
}
