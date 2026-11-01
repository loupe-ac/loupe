<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Yaml\Yaml;

final class Version20261031155928 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Write the shipped Lifecycle and Simple copies, with the bridge-disconnected marker for work that waits through a network outage, into every binding, and keep the epic branch and the approval rule of each copy';
    }

    /** The epic branch and the approval rule belong to the project, so each copy keeps its own value, or keeps none. */
    public function up(Schema $schema): void
    {
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

    /** The old copies are not kept, so nothing restores them. */
    #[\Override]
    public function down(Schema $schema): void
    {
    }
}
