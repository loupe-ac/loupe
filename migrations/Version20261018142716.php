<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018142716 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Replace the push login of a bridge with the git identity rows that it reports';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridges ADD git_identities JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE bridges ADD git_identities_reported_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        // @contract-phase: no code reads push_login, and the new CLI no longer sends the field
        $this->addSql('ALTER TABLE bridges DROP push_login');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridges ADD push_login VARCHAR(39) DEFAULT NULL');
        $this->addSql('ALTER TABLE bridges DROP git_identities');
        $this->addSql('ALTER TABLE bridges DROP git_identities_reported_at');
    }
}
