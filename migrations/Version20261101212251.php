<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101212251 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Drop the unread documents search vector column';
    }

    public function up(Schema $schema): void
    {
        // @contract-phase: the app stopped every read and write of the column in the release before this one, and that release is live.
        $this->addSql('ALTER TABLE documents DROP COLUMN search_vector');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE documents ADD search_vector TSVECTOR DEFAULT NULL');
    }
}
