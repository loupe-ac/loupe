<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Yaml\Yaml;

final class Version20261022180917 extends AbstractMigration
{
    private const array UNDO_RULES = ['product-design-undo' => 'product-design', 'tech-design-undo' => 'tech-design'];

    #[\Override]
    public function getDescription(): string
    {
        return 'Write the shipped Lifecycle copy, with the two undo rules, into every Lifecycle binding, and mark each past undo as already true so no card moves at deploy';
    }

    /**
     * The epic branch and the approval rule belong to the project, so each copy keeps its own value, or keeps none.
     * A card whose newest review of a design is a withdrawal of an approval gets a true state for the undo rule of that design.
     * The engine writes the fingerprint on its next pass, and a true state with no retry fires nothing.
     */
    public function up(Schema $schema): void
    {
        $source = Yaml::parseFile(__DIR__.'/../config/workflows/lifecycle.yaml');
        if (!\is_array($source) || !\is_int($source['version'] ?? null)) {
            throw new \LogicException('The shipped workflow template "lifecycle" has no version.');
        }
        $this->addSql(
            <<<'SQL'
                UPDATE workflow_bindings
                SET template_version = :version,
                    definition = (CAST(:definition AS JSONB) - 'epicBranch' - 'approvalCoversConflictResolution')
                        || CASE WHEN definition->'epicBranch' IS NULL THEN '{}'::jsonb ELSE jsonb_build_object('epicBranch', definition->'epicBranch') END
                        || CASE WHEN definition->'approvalCoversConflictResolution' IS NULL THEN '{}'::jsonb ELSE jsonb_build_object('approvalCoversConflictResolution', definition->'approvalCoversConflictResolution') END
                WHERE template_key = 'lifecycle'
                SQL,
            ['version' => $source['version'], 'definition' => json_encode($source, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION)],
        );

        foreach (self::UNDO_RULES as $ruleId => $tag) {
            $this->addSql(
                <<<'SQL'
                    INSERT INTO workflow_rule_states (id, card_id, project_id, rule_id, truth, updated_at)
                    SELECT gen_random_uuid(), past.id, past.project_id, :rule, TRUE, NOW()
                    FROM (
                        SELECT DISTINCT c.id, c.project_id
                        FROM board_cards c
                        JOIN workflow_bindings b ON b.project_id = c.project_id AND b.template_key = 'lifecycle'
                        JOIN board_card_documents cd ON cd.card_id = c.id
                        JOIN documents d ON d.id = cd.document_id AND d.archived_at IS NULL
                        JOIN document_tags dt ON dt.document_id = d.id
                        JOIN tags t ON t.id = dt.tag_id AND t.name = :tag
                        JOIN LATERAL (
                            SELECT v.id FROM document_versions v WHERE v.document_id = d.id ORDER BY v.version_number DESC LIMIT 1
                        ) newest ON TRUE
                        JOIN LATERAL (
                            SELECT r.version_id, r.sequence, r.verdict FROM reviews r WHERE r.version_id = newest.id ORDER BY r.sequence DESC LIMIT 1
                        ) last ON last.verdict = 'withdrawn'
                        WHERE EXISTS (
                            SELECT 1 FROM reviews p WHERE p.version_id = last.version_id AND p.sequence = last.sequence - 1 AND p.verdict = 'approved'
                        )
                    ) past
                    ON CONFLICT (card_id, rule_id) DO NOTHING
                    SQL,
                ['rule' => $ruleId, 'tag' => $tag],
            );
        }
    }

    /** The old copies are not kept, so nothing restores them. */
    #[\Override]
    public function down(Schema $schema): void
    {
    }
}
