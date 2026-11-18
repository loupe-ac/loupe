<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261117002346 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Store the SHA-256 hash of each beta invite token instead of the token';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_3891eaff5f37a13b');
        // @contract-phase: the old plain token must leave the table in this release, and the deploy notes tell the operator to fix forward, never roll back
        $this->addSql('ALTER TABLE beta_invites RENAME COLUMN token TO token_hash');
        $this->addSql("UPDATE beta_invites SET token_hash = encode(sha256(convert_to(token_hash, 'UTF8')), 'hex')");
        $this->addSql('CREATE UNIQUE INDEX UNIQ_3891EAFFB3BC57DA ON beta_invites (token_hash)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('A hash cannot give the token back.');
    }
}
