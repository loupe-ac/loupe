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
        return 'End each reported discovery run whose report document is archived, and close its inbox review';
    }

    public function up(Schema $schema): void
    {
        // The listener that closes the inbox review never sees these archives. The item closes first, while its run still reads reported.
        $this->addSql(<<<'SQL'
            UPDATE inbox_items item
            SET state = 'cancelled', closed_by = 'loupe', close_cause = 'fact-ended',
                note = 'A newer discovery run replaced this report, or a person archived it.',
                closed_at = now() AT TIME ZONE 'UTC', updated_at = now() AT TIME ZONE 'UTC'
            FROM discovery_runs run
            JOIN documents document ON document.id = run.report_document_id
            WHERE item.discovery_run_id = run.id
              AND item.project_id = run.project_id
              AND item.state = 'open'
              AND run.state = 'reported'
              AND document.archived_at IS NOT NULL
            SQL);
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
        // The runs stay ended and their reviews stay closed. The previous image reads a superseded run as no run.
    }
}
