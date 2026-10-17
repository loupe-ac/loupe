<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Yaml\Yaml;

final class Version20261017135823 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Write the shipped Lifecycle and Simple copies, whose open question marker matches only a blocking ask, into every binding, and keep the epic branch of each copy';
    }

    /** The marker reads its condition from the stored copy. The epic branch belongs to the project, so each copy keeps its own, or keeps none. */
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
                        definition = CASE
                            WHEN definition->'epicBranch' IS NULL THEN CAST(:definition AS JSONB) - 'epicBranch'
                            ELSE CAST(:definition AS JSONB) || jsonb_build_object('epicBranch', definition->'epicBranch')
                        END
                    WHERE template_key = :key
                    SQL,
                ['key' => $key, 'version' => $source['version'], 'definition' => json_encode($source, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION)],
            );
        }
    }

    /** The old copies are not kept, so nothing restores them. */
    #[\Override]
    public function down(Schema $schema): void
    {
    }
}
