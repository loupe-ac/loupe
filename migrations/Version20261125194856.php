<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261125194856 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'End each reported discovery run whose report document is archived';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE discovery_runs run
            SET state = 'superseded', ended_at = now() AT TIME ZONE 'UTC'
            FROM documents document
            WHERE document.id = run.report_document_id
              AND run.state = 'reported'
              AND document.archived_at IS NOT NULL
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // The runs stay ended. The previous image reads a superseded run as no run.
    }
}
