<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Yaml\Yaml;

final class Version20261107031500 extends AbstractMigration
{
    private const array TEMPLATES = ['lifecycle', 'simple'];

    #[\Override]
    public function getDescription(): string
    {
        return 'Write the shipped Lifecycle and Simple copies, with the workflow settings and requirements, into every binding, and move the epicBranch value of each copy into settingValues';
    }

    /**
     * The project keeps its own approval rule and its own epic branch. The old epicBranch key becomes a value of
     * the setting epic-branch-pattern in settingValues. A stored null meant no epic branches, so it turns the
     * setting epic-branches off. A copy with no key keeps the template default. A copy that has settingValues already keeps them, so a second run changes nothing.
     */
    public function up(Schema $schema): void
    {
        foreach (self::TEMPLATES as $key) {
            $source = Yaml::parseFile(__DIR__.'/../config/workflows/'.$key.'.yaml');
            if (!\is_array($source) || !\is_int($source['version'] ?? null)) {
                throw new \LogicException(\sprintf('The shipped workflow template "%s" has no version.', $key));
            }
            $this->addSql(
                <<<'SQL'
                    UPDATE workflow_bindings
                    SET template_version = :version,
                        definition = (CAST(:definition AS JSONB) - 'epicBranch' - 'settingValues' - 'approvalCoversConflictResolution')
                            || CASE
                                WHEN definition->'epicBranch' IS NULL AND definition->'settingValues' IS NULL THEN '{}'::jsonb
                                ELSE jsonb_build_object('settingValues', COALESCE(definition->'settingValues', '{}'::jsonb) || CASE
                                    WHEN jsonb_typeof(definition->'epicBranch') = 'string'
                                        THEN jsonb_build_object('epic-branch-pattern', definition->'epicBranch')
                                    WHEN jsonb_typeof(definition->'epicBranch') = 'null'
                                        THEN jsonb_build_object('epic-branches', FALSE)
                                    ELSE '{}'::jsonb
                                END)
                            END
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
