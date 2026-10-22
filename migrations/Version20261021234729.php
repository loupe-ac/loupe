<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261021234729 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add welcome_email_due_at to beta_invites with a partial index';
    }

    /**
     * Writes no row on purpose. Only BetaInvite::redeem() sets the date, so an
     * invite redeemed before this release never becomes due.
     */
    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE beta_invites ADD welcome_email_due_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_beta_invites_welcome_due ON beta_invites (welcome_email_due_at) WHERE (welcome_email_due_at IS NOT NULL)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_beta_invites_welcome_due');
        $this->addSql('ALTER TABLE beta_invites DROP welcome_email_due_at');
    }
}
