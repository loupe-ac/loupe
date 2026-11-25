<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261125144631 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Record the report version that a discovery review item opened for';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inbox_items ADD discovery_report_version INT DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inbox_items DROP discovery_report_version');
    }
}
