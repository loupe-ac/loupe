<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261028001629 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Rewrite the old merge causes of moved card events to carry the merged outcome';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            <<<'SQL'
                UPDATE board_card_events
                SET detail = jsonb_set(
                    detail,
                    '{cause}',
                    jsonb_build_object('type', 'workflow-rule', 'rule', 'merged', 'outcome', 'merged')
                        || CASE WHEN detail->'cause'->'pullRequest' IS NOT NULL THEN jsonb_build_object('pullRequest', detail->'cause'->'pullRequest') ELSE '{}'::jsonb END
                )
                WHERE kind = 'moved'
                    AND detail->'cause'->>'type' = 'merged'
                SQL,
        );
        $this->addSql(
            <<<'SQL'
                UPDATE board_card_events
                SET detail = jsonb_set(detail, '{cause,outcome}', '"merged"'::jsonb)
                WHERE kind = 'moved'
                    AND detail->'cause'->>'type' = 'workflow-rule'
                    AND detail->'cause'->>'rule' = 'merged'
                    AND detail->'cause'->'outcome' IS NULL
                SQL,
        );
    }

    /** The old cause shapes are not kept, so nothing restores them. */
    #[\Override]
    public function down(Schema $schema): void
    {
    }
}
