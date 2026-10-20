<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261020011800 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Drop the review commit column of a forge pull request';
    }

    public function up(Schema $schema): void
    {
        // @contract-phase: the review time replaced the review commit, so no code reads or writes forge_pull_requests.changes_requested_sha.
        $this->addSql('ALTER TABLE forge_pull_requests DROP changes_requested_sha');
    }

    /** The commits are not restored. */
    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forge_pull_requests ADD changes_requested_sha VARCHAR(64) DEFAULT NULL');
    }
}
