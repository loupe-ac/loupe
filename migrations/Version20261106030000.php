<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Yaml\Yaml;

final class Version20261106030000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Rename the repair worker to recovery in the stored work requests, worker runs, pauses and rule states, and the template copies';
    }

    /** The epic branch and the approval rule belong to the project, so each copy keeps its own value, or keeps none. */
    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE work_requests SET kind = 'recover' WHERE kind = 'repair'");
        $this->addSql("UPDATE bridge_worker_runs SET work_kind = 'recover' WHERE work_kind = 'repair'");
        $this->addSql("UPDATE bridge_worker_run_facts SET work_kind = 'recover' WHERE work_kind = 'repair'");
        $this->addSql("UPDATE bridge_worker_run_usage SET work_kind = 'recover' WHERE work_kind = 'repair'");
        $this->addSql("UPDATE card_pauses SET reason = 'recover-failed' WHERE reason = 'repair-failed'");
        $this->addSql("UPDATE card_pauses SET release_reason = 'recover-failed' WHERE release_reason = 'repair-failed'");
        $this->addSql("UPDATE workflow_rule_states SET last_refusal = 'recover-failed' WHERE last_refusal = 'repair-failed'");

        foreach (['lifecycle', 'simple'] as $key) {
            $source = Yaml::parseFile(__DIR__.'/../config/workflows/'.$key.'.yaml');
            if (!\is_array($source) || !\is_int($source['version'] ?? null)) {
                throw new \LogicException(\sprintf('The shipped workflow template "%s" has no version.', $key));
            }
            $this->addSql(
                <<<'SQL'
                    UPDATE workflow_bindings
                    SET template_version = :version,
                        definition = (CAST(:definition AS JSONB) - 'epicBranch' - 'approvalCoversConflictResolution')
                            || CASE WHEN definition->'epicBranch' IS NULL THEN '{}'::jsonb ELSE jsonb_build_object('epicBranch', definition->'epicBranch') END
                            || CASE WHEN definition->'approvalCoversConflictResolution' IS NULL THEN '{}'::jsonb ELSE jsonb_build_object('approvalCoversConflictResolution', definition->'approvalCoversConflictResolution') END
                    WHERE template_key = :key
                    SQL,
                ['version' => $source['version'], 'definition' => json_encode($source, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION), 'key' => $key],
            );
        }
    }

    /** The old name and the old copies are not kept, so nothing restores them. */
    #[\Override]
    public function down(Schema $schema): void
    {
    }
}
