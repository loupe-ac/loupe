<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018222041 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add resume_skipped to inbox_asks';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inbox_asks ADD resume_skipped BOOLEAN DEFAULT false NOT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inbox_asks DROP resume_skipped');
    }
}
